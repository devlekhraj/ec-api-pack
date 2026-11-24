<?php

declare(strict_types=1);

namespace EcApi\Domains\Article\Controllers;

use EcApi\Domains\Article\Resources\EditorDetailResource;
use EcApi\Domains\Shared\Helpers\CustomHelper;
use EcApi\Models\Article;
use EcApi\Models\ArticleVersion;
use EcApi\Models\ContentSection;
use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use EcApi\Support\NextRevalidator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ArticleRevisionController extends Controller
{
    public function reviseArticle(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $revision = DB::transaction(function () use ($id, $user): ArticleVersion {
                $sourceVersion = ArticleVersion::query()
                    ->with([
                        'authors',
                        'categories',
                        'sections.galleryUsages',
                        'bannerImage',
                        'seo',
                    ])
                    ->where('id', $id)
                    ->where('status', ArticleVersion::STATUS_PUBLISHED)
                    ->firstOrFail();

                // If an existing draft revision already exists for this parent version by the same user, resume it
                $existingRevision = ArticleVersion::query()
                    ->where('article_id', $sourceVersion->article_id)
                    ->where('parent_version_id', $sourceVersion->id)
                    ->where('version_type', ArticleVersion::TYPE_REVISION)
                    ->where('status', ArticleVersion::STATUS_DRAFT)
                    ->where('created_by_id', $user->id)
                    ->latest()
                    ->first();

                if ($existingRevision) {
                    return $existingRevision;
                }

                $originalVersionId = $sourceVersion->original_version_id ?? $sourceVersion->id;

                $revision = ArticleVersion::create([
                    'article_id' => $sourceVersion->article_id,
                    'parent_version_id' => $sourceVersion->id,
                    'original_version_id' => $originalVersionId,
                    'version_type' => ArticleVersion::TYPE_REVISION,
                    'status' => ArticleVersion::STATUS_DRAFT,
                    'title' => $sourceVersion->title,
                    'sub_title' => $sourceVersion->sub_title,
                    'slug' => $sourceVersion->slug,
                    'report_from' => $sourceVersion->report_from,
                    'meta' => $sourceVersion->meta,
                    'created_by_id' => $user->id,
                    'assigned_by_id' => null,
                    'assigned_to_id' => null,
                    'submitted_at' => null,
                    'published_by_id' => null,
                    'published_at' => null,
                ]);

                // Authors
                foreach ($sourceVersion->authors as $author) {
                    $revision->authors()->attach($author->id, [
                        'display_order' => $author->pivot->display_order ?? 0,
                    ]);
                }

                // Categories
                $categoryIds = $sourceVersion->categories->pluck('id')->all();
                if (! empty($categoryIds)) {
                    $revision->categories()->sync($categoryIds);
                }

                // Sections & gallery usages
                foreach ($sourceVersion->sections as $sec) {
                    $newSec = $revision->sections()->create([
                        'block_type' => $sec->block_type,
                        'sort_order' => $sec->sort_order,
                        'text' => $sec->text,
                        'payload' => $sec->payload,
                    ]);

                    foreach ($sec->galleryUsages as $usage) {
                        $newSec->galleryUsages()->create([
                            'gallery_id' => $usage->gallery_id,
                            'collection' => $usage->collection,
                            'is_primary' => $usage->is_primary,
                            'photo_credit' => $usage->photo_credit,
                            'caption' => $usage->caption,
                            'alt_text' => $usage->alt_text,
                            'seq_no' => $usage->seq_no,
                            'custom_properties' => $usage->custom_properties,
                            'created_by' => $user->id,
                        ]);
                    }
                }

                // Banner image via relation
                $bannerUsage = $sourceVersion->bannerImage;
                if ($bannerUsage) {
                    $revision->galleryUsages()->create([
                        'gallery_id' => $bannerUsage->gallery_id,
                        'collection' => $bannerUsage->collection ?? GalleryUsage::COLLECTION_BANNER,
                        'is_primary' => true,
                        'photo_credit' => $bannerUsage->photo_credit,
                        'caption' => $bannerUsage->caption,
                        'alt_text' => $bannerUsage->alt_text,
                        'seq_no' => $bannerUsage->seq_no ?? 0,
                        'custom_properties' => $bannerUsage->custom_properties,
                        'created_by_id' => $user->id,
                    ]);
                }

                // SEO via relation
                $seo = $sourceVersion->seo;
                if ($seo) {
                    $revision->seo()->create([
                        'canonical_url' => $seo->canonical_url,
                        'meta_title' => $seo->meta_title,
                        'meta_descriptions' => $seo->meta_descriptions,
                        'og_title' => $seo->og_title,
                        'og_description' => $seo->og_description,
                        'og_image_url' => $seo->og_image_url,
                        'twitter_title' => $seo->twitter_title,
                        'twitter_description' => $seo->twitter_description,
                        'twitter_image_url' => $seo->twitter_image_url,
                        'focus_keywords' => $seo->focus_keywords,
                        'json_ld' => $seo->json_ld,
                        'seo_extra' => $seo->seo_extra,
                        'ai_optimized' => false,
                        'finalized' => false,
                        'locked' => false,
                    ]);
                }

                return $revision;
            });

            return response()->json([
                'message' => 'Article revision draft prepared successfully.',
                'data' => [
                    'id' => $revision->id,
                ],
                'success' => true,
            ], 201);
        } catch (Throwable $th) {
            Log::error('Failed to create article revision: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => $th->getMessage() ?: 'Failed to create article revision.',
            ], 422);
        }
    }

    public function getRevisionDraftDetail(string $id): JsonResponse
    {
        $revisionVersion = ArticleVersion::query()
            ->with([
                'parentVersion',
                'originalVersion',
                'authors',
                'categories',
                'sections.galleryUsages.gallery',
                'bannerImage.gallery',
                'files',
                'publishedBy',
                'assignedTo',
            ])
            ->where('id', $id)
            ->where('status', ArticleVersion::STATUS_DRAFT)
            ->where('version_type', ArticleVersion::TYPE_REVISION)
            ->firstOrFail();

        $parent = $revisionVersion->parentVersion;
        if (! $parent || $parent->status !== ArticleVersion::STATUS_PUBLISHED) {
            return response()->json([
                'message' => 'Invalid revision: The parent version for this revision must be in published status.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'article_id' => $revisionVersion->article_id,
                'article_version_id' => $revisionVersion->id,
                'parent_version_id' => $revisionVersion->parent_version_id,
                'original_version_id' => $revisionVersion->original_version_id,
                'parent_version' => [
                    'id' => $parent->id,
                    'title' => $parent->title,
                    'published_at' => $parent->published_at,
                    'slug' => $parent->slug,
                    'status' => $parent->status,
                ],
                'version_type' => $revisionVersion->version_type,
                'status' => $revisionVersion->status,
                'title' => $revisionVersion->title,
                'sub_title' => $revisionVersion->sub_title,
                'slug' => $revisionVersion->slug,
                'report_from' => $revisionVersion->report_from,
                'is_exclusive' => (bool) ($revisionVersion->meta['is_exclusive'] ?? false),
                'has_other_category' => (bool) ($revisionVersion->meta['has_other_category'] ?? false),
                'author_ids' => $revisionVersion->authors->pluck('id')->values()->all(),
                'category_ids' => $revisionVersion->categories->pluck('id')->values()->all(),
                'categories' => $revisionVersion->categories->map(static fn($c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                ])->values()->all(),
                'sections' => $revisionVersion->sections->map(static fn($s): array => EditorDetailResource::sectionPayload($s))->values()->all(),
                'published_by' => $revisionVersion->publishedBy ? [
                    'id' => $revisionVersion->publishedBy->id,
                    'name' => $revisionVersion->publishedBy->name,
                    'email' => $revisionVersion->publishedBy->email,
                ] : null,
                'assigned_to' => $revisionVersion->assignedTo ? [
                    'id' => $revisionVersion->assignedTo->id,
                    'name' => $revisionVersion->assignedTo->name,
                    'email' => $revisionVersion->assignedTo->email,
                ] : null,
                'published_at' => $revisionVersion->published_at,
                'submitted_at' => $revisionVersion->submitted_at,
                'files' => $revisionVersion->files()->get()->map(static function ($file): array {
                    return [
                        'id' => $file->id,
                        'type' => $file->mime_type,
                        'filename' => $file->filename,
                        'url' => $file?->url,
                        'size' => CustomHelper::humanFileSize((int) ($file->size ?? 0)),
                    ];
                })->all(),
                'banner_image' => $revisionVersion->bannerImage ? [
                    'id' => $revisionVersion->bannerImage->id,
                    'url' => $revisionVersion->bannerImage->gallery?->url,
                    'alt_text' => $revisionVersion->bannerImage->alt_text,
                    'photo_credit' => $revisionVersion->bannerImage->photo_credit,
                    'caption' => $revisionVersion->bannerImage->caption,
                ] : null,
            ],
        ]);
    }

    public function updateRevisionDraft(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required'],
            'title' => ['required', 'string', 'max:255'],
            'sub_title' => ['nullable', 'string', 'max:1000'],
            'slug' => ['required', 'string', 'max:255'],
            'report_from' => ['nullable', 'string', 'max:255'],
            'is_exclusive' => ['nullable', 'boolean'],
            'has_other_category' => ['nullable', 'boolean'],
            'author_ids' => ['nullable', 'array'],
            'author_ids.*' => ['integer', 'exists:users,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'sections' => ['nullable', 'array'],
            'sections.*.block_type' => ['nullable', 'string', 'max:50'],
            'sections.*.type' => ['nullable', 'string', 'max:50'],
            'sections.*.sort_order' => ['nullable', 'integer'],
            'sections.*.text' => ['nullable', 'string'],
            'sections.*.payload' => ['nullable'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $revisionVersion = DB::transaction(function () use ($validated, $id, $user): ArticleVersion {
                $revisionVersion = ArticleVersion::query()
                    ->where('id', $id)
                    ->where('status', ArticleVersion::STATUS_DRAFT)
                    ->where('version_type', ArticleVersion::TYPE_REVISION)
                    ->firstOrFail();

                $parent = $revisionVersion->parentVersion;
                if (! $parent || $parent->status !== ArticleVersion::STATUS_PUBLISHED) {
                    throw new \RuntimeException('Cannot save revision: The parent version is not published.');
                }

                $revisionVersion->fill([
                    'title' => $validated['title'],
                    'sub_title' => $validated['sub_title'] ?? null,
                    'slug' => $validated['slug'],
                    'report_from' => $validated['report_from'] ?? null,
                    'meta' => array_merge(is_array($revisionVersion->meta) ? $revisionVersion->meta : [], [
                        'is_exclusive' => (bool) ($validated['is_exclusive'] ?? false),
                        'has_other_category' => (bool) ($validated['has_other_category'] ?? false),
                    ]),
                ]);
                $revisionVersion->save();

                if (isset($validated['author_ids'])) {
                    $revisionVersion->authors()->sync(
                        array_values(array_map('intval', $validated['author_ids']))
                    );
                }

                if (isset($validated['category_ids'])) {
                    $revisionVersion->categories()->sync(
                        array_values(array_map('intval', $validated['category_ids']))
                    );
                }

                if (array_key_exists('sections', $validated)) {
                    $this->deleteSectionGalleryUsages($revisionVersion);
                    $revisionVersion->sections()->delete();

                    foreach ($validated['sections'] ?? [] as $index => $sec) {
                        $sortOrder = (int) ($sec['sort_order'] ?? $index);
                        $blockType = (string) ($sec['block_type'] ?? ($sec['type'] ?? 'text_area'));
                        $text = isset($sec['text']) ? (string) $sec['text'] : null;
                        $payload = is_array($sec['payload'] ?? null) ? $sec['payload'] : [];

                        $section = $revisionVersion->sections()->create([
                            'block_type' => $blockType,
                            'sort_order' => $sortOrder,
                            'text' => $text,
                            'payload' => $payload,
                        ]);
                        $this->syncSectionGalleryUsages($section, $payload, $user?->id);
                    }
                }

                return $revisionVersion->refresh();
            });

            return response()->json([
                'message' => 'Revision draft updated successfully.',
                'data' => [
                    'id' => $revisionVersion->id,
                    'sections' => $revisionVersion->sections()->get(),
                ],
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to update revision draft: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to update revision draft: ' . $th->getMessage(),
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    private function deleteSectionGalleryUsages(ArticleVersion $version): void
    {
        foreach ($version->sections as $section) {
            $section->galleryUsages()->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncSectionGalleryUsages(ContentSection $section, array $payload, ?int $createdById): void
    {
        $blockType = (string) $section->block_type;

        if (! in_array($blockType, ['image', 'full_width_image', 'multi_image'], true)) {
            return;
        }

        $section->galleryUsages()->delete();

        if ($blockType !== 'multi_image' && (($payload['mode'] ?? 'single') !== 'grid')) {
            $galleryId = $this->galleryIdFromPayload($payload);

            if (! $galleryId) {
                return;
            }

            $section->galleryUsages()->updateOrCreate(
                [
                    'gallery_id' => $galleryId,
                    'collection' => GalleryUsage::COLLECTION_IMAGE,
                ],
                [
                    'created_by_id' => $createdById,
                    'seq_no' => 0,
                    'is_primary' => true,
                    'alt_text' => $this->nullablePayloadString($payload, 'alt'),
                    'caption' => $this->nullablePayloadString($payload, 'caption'),
                    'photo_credit' => $this->nullablePayloadString($payload, 'credit'),
                    'title' => $this->nullablePayloadString($payload, 'title'),
                    'custom_properties' => [
                        'link' => $this->nullablePayloadString($payload, 'link'),
                        'block_type' => $blockType,
                    ],
                ],
            );

            return;
        }

        $images = is_array($payload['images'] ?? null) ? $payload['images'] : [];

        foreach (array_values($images) as $index => $image) {
            if (! is_array($image)) {
                continue;
            }

            $galleryId = $this->galleryIdFromPayload($image);

            if (! $galleryId) {
                continue;
            }

            $section->galleryUsages()->updateOrCreate(
                [
                    'gallery_id' => $galleryId,
                    'collection' => GalleryUsage::COLLECTION_MULTI_IMAGE,
                ],
                [
                    'created_by_id' => $createdById,
                    'seq_no' => $index,
                    'is_primary' => $index === 0,
                    'alt_text' => $this->nullablePayloadString($image, 'alt'),
                    'caption' => $this->nullablePayloadString($payload, 'caption'),
                    'photo_credit' => $this->nullablePayloadString($payload, 'credit'),
                    'custom_properties' => [
                        'link' => $this->nullablePayloadString($payload, 'link'),
                        'columns' => (int) ($payload['columns'] ?? 2),
                        'block_type' => $blockType,
                    ],
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function galleryIdFromPayload(array $payload): ?int
    {
        $galleryId = $payload['gallery_id'] ?? ($payload['id'] ?? null);

        if (is_numeric($galleryId)) {
            return (int) $galleryId;
        }

        $src = (string) ($payload['src'] ?? ($payload['url'] ?? ''));

        if ($src === '') {
            return null;
        }

        $path = parse_url($src, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $filename = basename($path);

        if ($filename === '') {
            return null;
        }

        return Gallery::query()
            ->where('filename', $filename)
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullablePayloadString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function publishRevision(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $publishedVersion = DB::transaction(function () use ($id, $user): ArticleVersion {
                $revision = ArticleVersion::query()
                    ->where('id', $id)
                    ->where('version_type', ArticleVersion::TYPE_REVISION)
                    ->where('status', ArticleVersion::STATUS_DRAFT)
                    ->firstOrFail();

                $publishedAt = now();

                $revision->forceFill([
                    'status' => ArticleVersion::STATUS_PUBLISHED,
                    'published_by_id' => $user->id,
                    'published_at' => $publishedAt,
                ])->save();

                $article = $revision->article;
                if ($article) {
                    $seo = $revision->seo;
                    if ($seo && is_array($seo->json_ld)) {
                        $seo->forceFill([
                            'json_ld' => array_merge($seo->json_ld, [
                                'datePublished' => $article->first_published_at?->toISOString() ?? $publishedAt->toISOString(),
                                'dateModified' => $publishedAt->toISOString(),
                            ]),
                        ])->save();
                    }

                    $article->forceFill([
                        'status' => Article::STATUS_PUBLISHED,
                        'latest_version_id' => $revision->id,
                        'published_version_id' => $revision->id,
                        'first_published_at' => $article->first_published_at ?? $publishedAt,
                        'last_published_at' => $publishedAt,
                    ])->save();
                }

                return $revision->refresh();
            });

            $publishedVersion->loadMissing(['authors', 'categories']);
            NextRevalidator::revalidate(NextRevalidator::articleTags($publishedVersion));

            return response()->json([
                'message' => 'Article revision published successfully.',
                'data' => [
                    'id' => $publishedVersion->id,
                    'article_id' => $publishedVersion->article_id,
                ],
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to publish article revision: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to publish article revision: ' . $th->getMessage(),
                'success' => false,
            ], 500);
        }
    }
}
