<?php

declare(strict_types=1);

namespace EcApi\Domains\Article\Controllers;

use EcApi\Domains\Article\Resources\EditorDetailResource;
use EcApi\Models\ArticleVersion;
use EcApi\Models\ContentSection;
use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use EcApi\Models\LiveUpdate;
use EcApi\Support\NextRevalidator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LiveUpdateController extends Controller
{
    public function index(Request $request, $versionId): JsonResponse
    {
        $version = ArticleVersion::query()->findOrFail($versionId);

        $liveUpdates = LiveUpdate::query()
            ->where('article_version_id', $version->id)
            ->with([
                'banner.gallery',
                'bannerImage.gallery',
                'sections.galleryUsages.gallery',
                'creator',
            ])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $liveUpdates->map(fn(LiveUpdate $item): array => $this->transformLiveUpdate($item)),
        ]);
    }

    public function show($id): JsonResponse
    {
        $item = LiveUpdate::query()
            ->with([
                'banner.gallery',
                'bannerImage.gallery',
                'sections.galleryUsages.gallery',
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->transformLiveUpdate($item),
        ]);
    }

    public function store(Request $request, $versionId): JsonResponse
    {
        $version = ArticleVersion::query()->findOrFail($versionId);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'sub_title' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:' . LiveUpdate::STATUS_DRAFT . ',' . LiveUpdate::STATUS_PUBLISHED],
            'published_at' => ['nullable', 'string'],
            'banner' => ['nullable'],
            'gallery_id' => ['nullable'],
            'sections' => ['nullable', 'array'],
        ]);

        $user = $request->user();

        $liveUpdate = DB::transaction(function () use ($version, $validated, $user): LiveUpdate {
            $liveUpdate = LiveUpdate::create([
                'article_version_id' => $version->id,
                'title' => $validated['title'] ?? null,
                'sub_title' => $validated['sub_title'] ?? null,
                'status' => $validated['status'] ?? LiveUpdate::STATUS_PUBLISHED,
                'created_by' => $user?->id,
                'published_at' => !empty($validated['published_at']) ? $validated['published_at'] : now(),
            ]);

            $this->syncBanner($liveUpdate, $validated, $user?->id);
            $this->syncSections($liveUpdate, $validated['sections'] ?? [], $user?->id);

            return $liveUpdate->load(['banner.gallery', 'bannerImage.gallery', 'sections.galleryUsages.gallery', 'creator']);
        });

        $version->loadMissing(['categories', 'authors']);
        NextRevalidator::revalidate(NextRevalidator::articleTags($version));

        return response()->json([
            'success' => true,
            'message' => 'Live update created successfully.',
            'data' => $this->transformLiveUpdate($liveUpdate),
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $liveUpdate = LiveUpdate::query()->findOrFail($id);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'sub_title' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:' . LiveUpdate::STATUS_DRAFT . ',' . LiveUpdate::STATUS_PUBLISHED],
            'published_at' => ['nullable', 'string'],
            'banner' => ['nullable'],
            'gallery_id' => ['nullable'],
            'sections' => ['nullable', 'array'],
        ]);

        $user = $request->user();

        $liveUpdate = DB::transaction(function () use ($liveUpdate, $validated, $user): LiveUpdate {
            $liveUpdate->update([
                'title' => $validated['title'] ?? $liveUpdate->title,
                'sub_title' => $validated['sub_title'] ?? null,
                'status' => $validated['status'] ?? $liveUpdate->status,
                'published_at' => !empty($validated['published_at']) ? $validated['published_at'] : $liveUpdate->published_at,
            ]);

            if (array_key_exists('banner', $validated) || array_key_exists('gallery_id', $validated)) {
                $this->syncBanner($liveUpdate, $validated, $user?->id);
            }

            if (array_key_exists('sections', $validated)) {
                $this->syncSections($liveUpdate, $validated['sections'] ?? [], $user?->id);
            }

            return $liveUpdate->load(['banner.gallery', 'bannerImage.gallery', 'sections.galleryUsages.gallery', 'creator']);
        });

        $version = $liveUpdate->articleVersion;
        if ($version) {
            $version->loadMissing(['categories', 'authors']);
            NextRevalidator::revalidate(NextRevalidator::articleTags($version));
        }

        return response()->json([
            'success' => true,
            'message' => 'Live update updated successfully.',
            'data' => $this->transformLiveUpdate($liveUpdate),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $liveUpdate = LiveUpdate::query()->findOrFail($id);
        $version = $liveUpdate->articleVersion;

        DB::transaction(function () use ($liveUpdate): void {
            foreach ($liveUpdate->sections as $section) {
                $section->galleryUsages()->delete();
            }

            $liveUpdate->galleryUsages()->delete();
            $liveUpdate->sections()->delete();
            $liveUpdate->delete();
        });

        if ($version) {
            $version->loadMissing(['categories', 'authors']);
            NextRevalidator::revalidate(NextRevalidator::articleTags($version));
        }

        return response()->json([
            'success' => true,
            'message' => 'Live update deleted successfully.',
        ]);
    }

    private function syncBanner(LiveUpdate $liveUpdate, array $validated, ?int $userId): void
    {
        $banner = $validated['banner'] ?? null;
        $galleryId = $validated['gallery_id'] ?? ($banner['gallery_id'] ?? ($banner['id'] ?? null));

        if (empty($galleryId)) {
            if ($banner === null && array_key_exists('banner', $validated)) {
                $liveUpdate->banner()->delete();
            }
            return;
        }

        $gallery = Gallery::find($galleryId);
        if (!$gallery) {
            return;
        }

        $liveUpdate->banner()->delete();

        $liveUpdate->galleryUsages()->create([
            'gallery_id' => $gallery->id,
            'collection' => GalleryUsage::COLLECTION_BANNER,
            'created_by_id' => $userId,
            'is_primary' => true,
            'alt_text' => $banner['alt_text'] ?? ($banner['alt'] ?? ($gallery->alt_text ?? null)),
            'caption' => $banner['caption'] ?? ($gallery->caption ?? null),
            'photo_credit' => $banner['photo_credit'] ?? ($banner['credit'] ?? ($gallery->photo_credit ?? null)),
        ]);
    }

    private function syncSections(LiveUpdate $liveUpdate, array $sections, ?int $userId): void
    {
        foreach ($liveUpdate->sections as $sec) {
            $sec->galleryUsages()->delete();
        }
        $liveUpdate->sections()->delete();

        foreach ($sections as $index => $sec) {
            $sortOrder = (int) ($sec['sort_order'] ?? $index);
            $blockType = (string) ($sec['block_type'] ?? ($sec['type'] ?? 'rich_text'));
            $text = isset($sec['text']) ? (string) $sec['text'] : null;
            $payload = is_array($sec['payload'] ?? null) ? $sec['payload'] : [];

            $section = $liveUpdate->sections()->create([
                'block_type' => $blockType,
                'sort_order' => $sortOrder,
                'text' => $text,
                'payload' => $payload,
            ]);

            $this->syncSectionGalleryUsage($section, $payload, $userId);
        }
    }

    private function syncSectionGalleryUsage(ContentSection $section, array $payload, ?int $userId): void
    {
        if (in_array((string) $section->block_type, ['image', 'full_width_image'], true) && (($payload['mode'] ?? 'single') !== 'grid')) {
            $galleryId = $payload['gallery_id'] ?? ($payload['image']['gallery_id'] ?? null);
            if ($galleryId) {
                $section->galleryUsages()->create([
                    'gallery_id' => $galleryId,
                    'collection' => GalleryUsage::COLLECTION_IMAGE,
                    'created_by_id' => $userId,
                    'is_primary' => true,
                    'alt_text' => $payload['alt'] ?? null,
                    'caption' => $payload['caption'] ?? null,
                    'photo_credit' => $payload['credit'] ?? null,
                ]);
            }
        }
    }

    private function transformLiveUpdate(LiveUpdate $item): array
    {
        $bannerUsage = $item->banner ?? $item->bannerImage;
        $bannerData = null;
        if ($bannerUsage) {
            $bannerData = [
                'id' => $bannerUsage->id,
                'gallery_id' => $bannerUsage->gallery_id,
                'url' => $bannerUsage->url ?? $bannerUsage->gallery?->url,
                'alt_text' => $bannerUsage->alt_text,
                'caption' => $bannerUsage->caption,
                'photo_credit' => $bannerUsage->photo_credit,
            ];
        }

        return [
            'id' => $item->id,
            'article_version_id' => $item->article_version_id,
            'title' => $item->title ?? '',
            'sub_title' => $item->sub_title ?? '',
            'status' => $item->status ?? LiveUpdate::STATUS_PUBLISHED,
            'created_by' => $item->created_by,
            'creator' => $item->creator ? [
                'id' => $item->creator->id,
                'name' => $item->creator->name,
                'email' => $item->creator->email,
            ] : null,
            'published_at' => $item->published_at?->toISOString() ?? $item->created_at?->toISOString(),
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
            'banner' => $bannerData,
            'sections' => $item->sections->map(static fn(ContentSection $s): array => EditorDetailResource::sectionPayload($s))->values()->all(),
        ];
    }
}
