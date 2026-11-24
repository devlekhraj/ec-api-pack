<?php

declare(strict_types=1);

namespace EcApi\Domains\Article\Controllers;

use EcApi\Models\Article;
use EcApi\Models\ArticleVersion;
use EcApi\Models\ArticleVersionSeo;
use EcApi\Models\ContentSection;
use EcApi\Domains\Article\Resources\EditorDetailResource;
use EcApi\Models\Category;
use EcApi\Domains\Shared\Helpers\CustomHelper;
use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use EcApi\Support\NextRevalidator;
use EcApi\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ArticleController extends Controller
{
    private const EMPTY_DRAFT_LIMIT = 2;

    public function createArticle(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $emptyDraftsCount = ArticleVersion::query()
            ->where('version_type', ArticleVersion::TYPE_ORIGINAL)
            ->where('status', ArticleVersion::STATUS_DRAFT)
            ->where('created_by_id', $user->id)
            ->where(fn ($query) => $query->whereNull('title')->orWhere('title', ''))
            ->whereNull('sub_title')
            ->whereNull('report_from')
            ->whereHas('article', fn ($query) => $query->where('status', Article::STATUS_DRAFT))
            ->whereDoesntHave('categories')
            ->whereDoesntHave('sections')
            ->whereDoesntHave('files')
            ->count();

        if ($emptyDraftsCount >= self::EMPTY_DRAFT_LIMIT) {
            return response()->json([
                'message' => 'Already 2 empty draft exists.',
                'success' => false,
            ], 409);
        }

        try {
            $article = DB::transaction(function () use ($user): Article {
                $article = Article::query()->create([
                    'status' => Article::STATUS_DRAFT,
                    'created_by_id' => $user->id,
                    'first_published_at' => null,
                    'last_published_at' => null,
                ]);

                $version = $article->versions()->create([
                    'version_type' => ArticleVersion::TYPE_ORIGINAL,
                    'status' => ArticleVersion::STATUS_DRAFT,
                    'title' => '',
                    'sub_title' => null,
                    'slug' => 'draft-' . now()->format('Y-m-d') . '-' . Str::lower(Str::random(8)),
                    'report_from' => null,
                    'meta' => null,
                    'created_by_id' => $user->id,
                    'assigned_by_id' => null,
                    'assigned_to_id' => null,
                    'submitted_at' => null,
                    'published_by_id' => null,
                    'published_at' => null,
                ]);

                $article->forceFill([
                    'latest_version_id' => $version->id,
                ])->save();

                $version->authors()->syncWithoutDetaching([
                    $user->id => [
                        'display_order' => 1,
                    ],
                ]);

                return $article->refresh();
            });

            return response()->json([
                'message' => 'Article draft created successfully.',
                'data' => [
                    'id' => $article->id,
                ],
                'success' => true,
            ], 201);
        } catch (Throwable $th) {
            Log::error('Failed to create article draft: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to create article draft.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function getAuthorDraftDetail(string $id): JsonResponse
    {
        $draftVersion = ArticleVersion::query()
            ->with([
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
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'article_id' => $draftVersion->article_id,
                'article_version_id' => $draftVersion->id,
                'version_type' => $draftVersion->version_type,
                'status' => $draftVersion->status,
                'title' => $draftVersion->title,
                'sub_title' => $draftVersion->sub_title,
                'slug' => $draftVersion->slug,
                'report_from' => $draftVersion->report_from,
                'is_exclusive' => (bool) ($draftVersion->meta['is_exclusive'] ?? false),
                'has_other_category' => (bool) ($draftVersion->meta['has_other_category'] ?? false),
                'author_ids' => $draftVersion->authors->pluck('id')->values()->all(),
                'category_ids' => $draftVersion->categories->pluck('id')->values()->all(),
                'categories' => $draftVersion->categories->map(static fn($c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                ])->values()->all(),
                'sections' => $draftVersion->sections->map(static fn($s): array => EditorDetailResource::sectionPayload($s))->values()->all(),
                'published_by' => $draftVersion->publishedBy ? [
                    'id' => $draftVersion->publishedBy->id,
                    'name' => $draftVersion->publishedBy->name,
                    'email' => $draftVersion->publishedBy->email,
                ] : null,
                'assigned_to' => $draftVersion->assignedTo ? [
                    'id' => $draftVersion->assignedTo->id,
                    'name' => $draftVersion->assignedTo->name,
                    'email' => $draftVersion->assignedTo->email,
                ] : null,
                'published_at' => $draftVersion->published_at,
                'submitted_at' => $draftVersion->submitted_at,
                'files' => $draftVersion->files()->get()->map(static function ($file): array {
                    return [
                        'id' => $file->id,
                        'type' => $file->mime_type,
                        'filename' => $file->filename,
                        'url' => $file?->url,
                        'size' => CustomHelper::humanFileSize((int) ($file->size ?? 0)),
                    ];
                })->all(),
                'banner_image' => $draftVersion->bannerImage ? [
                    'id' => $draftVersion->bannerImage->id,
                    'url' => $draftVersion->bannerImage->gallery?->url,
                    'alt_text' => $draftVersion->bannerImage->alt_text,
                    'photo_credit' => $draftVersion->bannerImage->photo_credit,
                    'caption' => $draftVersion->bannerImage->caption,
                ] : null,

            ],
        ]);
    }

    public function deleteAuthorDraft(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $draftVersion = ArticleVersion::query()
            ->where('id', $id)
            ->where('status', ArticleVersion::STATUS_DRAFT)
            ->first();

        if (! $draftVersion) {
            return response()->json([
                'message' => 'Draft article not found.',
            ], 404);
        }

        if ($draftVersion->created_by_id !== $user->id) {
            return response()->json([
                'message' => 'You are not allowed to delete this draft.',
            ], 403);
        }

        try {
            DB::transaction(fn () => $this->purgeAuthorDraft($draftVersion));

            return response()->json([
                'message' => 'Draft article deleted successfully.',
                'data' => [
                    'id' => $id,
                ],
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to delete author draft: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to delete draft article.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function bulkDeleteAuthorDrafts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $ids = collect($validated['ids'])
            ->map(static fn ($id) => (string) $id)
            ->unique()
            ->values();

        try {
            $deleted = DB::transaction(function () use ($ids, $user): array {
                $drafts = ArticleVersion::query()
                    ->whereIn('id', $ids->all())
                    ->where('status', ArticleVersion::STATUS_DRAFT)
                    ->where('created_by_id', $user->id)
                    ->get();

                foreach ($drafts as $draft) {
                    $this->purgeAuthorDraft($draft);
                }

                return $drafts->pluck('id')->map(static fn ($id) => (string) $id)->all();
            });

            if ($deleted === []) {
                return response()->json([
                    'message' => 'No deletable draft articles were found.',
                ], 422);
            }

            return response()->json([
                'message' => count($deleted) === 1
                    ? '1 draft article deleted successfully.'
                    : count($deleted) . ' draft articles deleted successfully.',
                'data' => [
                    'ids' => $deleted,
                    'deleted_count' => count($deleted),
                    'skipped_count' => $ids->count() - count($deleted),
                ],
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to bulk delete author drafts: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to delete the selected draft articles.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    private function purgeAuthorDraft(ArticleVersion $draftVersion): void
    {
        // Children that would otherwise block the delete (or be orphaned).
        $this->deleteSectionGalleryUsages($draftVersion);
        $draftVersion->sections()->delete();
        $draftVersion->authors()->detach();
        $draftVersion->categories()->detach();
        $draftVersion->files()->detach();
        $draftVersion->aiRequests()->delete();
        $draftVersion->seo()->delete();

        DB::table('gallery_usages')
            ->where('usage_type', 'article_versions')
            ->where('usage_id', $draftVersion->id)
            ->delete();

        $article = $draftVersion->article;

        $draftVersion->delete();

        if (! $article) {
            return;
        }

        $remainingVersion = $article->versions()
            ->orderByDesc('id')
            ->first();

        if (! $remainingVersion) {
            $article->delete();

            return;
        }

        $article->fill([
            'status' => $remainingVersion->status ?? Article::STATUS_DRAFT,
            'latest_version_id' => $remainingVersion->id,
            'published_version_id' => $article->published_version_id === $draftVersion->id
                ? null
                : $article->published_version_id,
        ])->save();
    }

    private function deleteSectionGalleryUsages(ArticleVersion $version): void
    {
        $sectionIds = $version->sections()->pluck('id');

        if ($sectionIds->isEmpty()) {
            return;
        }

        GalleryUsage::query()
            ->where('usage_type', 'content_sections')
            ->whereIn('usage_id', $sectionIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncSectionGalleryUsages(ContentSection $section, array $payload, ?int $createdById): void
    {
        $blockType = (string) $section->block_type;

        if (! in_array($blockType, ['image', 'full_width_image', 'multi_image', 'gallery'], true)) {
            return;
        }

        GalleryUsage::query()
            ->where('usage_type', 'content_sections')
            ->where('usage_id', $section->id)
            ->delete();

        if (! in_array($blockType, ['multi_image', 'gallery'], true) && (($payload['mode'] ?? 'single') !== 'grid')) {
            $galleryId = $this->galleryIdFromPayload($payload);

            if (! $galleryId) {
                return;
            }

            GalleryUsage::query()->updateOrCreate(
                [
                    'gallery_id' => $galleryId,
                    'usage_type' => 'content_sections',
                    'usage_id' => $section->id,
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

            GalleryUsage::query()->updateOrCreate(
                [
                    'gallery_id' => $galleryId,
                    'usage_type' => 'content_sections',
                    'usage_id' => $section->id,
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

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function submitEditorialReview(string $id): JsonResponse
    {
        try {
            $draftVersion = DB::transaction(function () use ($id): ArticleVersion {
                $draftVersion = ArticleVersion::query()
                    ->where('id', $id)
                    ->where('status', ArticleVersion::STATUS_DRAFT)
                    ->firstOrFail();

                $draftVersion->update([
                    'status' => ArticleVersion::STATUS_REVIEW_REQUEST,
                    'submitted_at' => now(),
                ]);

                $draftVersion->article()->update([
                    'status' => Article::STATUS_REVIEW_REQUEST,
                ]);

                return $draftVersion->refresh();
            });

            return response()->json([
                'message' => 'Article submitted successfully',
                'success' => true,
                'data' => $draftVersion,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to update author draft: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to update author draft.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function updateAuthorDraft(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required'],
            'title' => ['required', 'string', 'max:255'],
            'sub_title' => ['nullable', 'string', 'max:1000'],
            'slug' => ['required', 'string', 'max:255'],
            'report_from' => ['nullable', 'string', 'max:255'],
            'is_exclusive' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_breaking' => ['nullable', 'boolean'],
            'tags' => ['nullable'],
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
            'banner_image' => ['nullable', 'array'],
            'banner_image.id' => ['nullable', 'integer', 'exists:galleries,id'],
            'banner_image.alt' => ['nullable', 'string', 'max:1000'],
            'banner_image.caption' => ['nullable', 'string', 'max:2000'],
            'banner_image.photo_credit' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $draftVersion = DB::transaction(function () use ($validated, $id, $user): ArticleVersion {
                $draftVersion = ArticleVersion::query()
                    ->where('id', $id)
                    ->where('status', ArticleVersion::STATUS_DRAFT)
                    ->firstOrFail();

                $rawTags = $validated['tags'] ?? null;
                $formattedTags = is_array($rawTags) ? array_values(array_filter($rawTags)) : (is_string($rawTags) ? array_values(array_filter(array_map('trim', explode(',', $rawTags)))) : null);

                $draftVersion->fill([
                    'title' => $validated['title'],
                    'sub_title' => $validated['sub_title'] ?? null,
                    'slug' => $validated['slug'],
                    'report_from' => $validated['report_from'] ?? null,
                    'meta' => array_merge(is_array($draftVersion->meta) ? $draftVersion->meta : [], array_filter([
                        'is_exclusive' => (bool) ($validated['is_exclusive'] ?? false),
                        'is_featured' => (bool) ($validated['is_featured'] ?? false),
                        'is_breaking' => (bool) ($validated['is_breaking'] ?? false),
                        'tags' => $formattedTags ?? ($draftVersion->meta['tags'] ?? []),
                        'has_other_category' => (bool) ($validated['has_other_category'] ?? false),
                    ], static fn ($val) => $val !== null)),
                ]);
                $draftVersion->save();

                if (isset($validated['author_ids'])) {
                    $draftVersion->authors()->sync(
                        array_values(array_map('intval', $validated['author_ids']))
                    );
                }

                if (isset($validated['category_ids'])) {
                    $draftVersion->categories()->sync(
                        array_values(array_map('intval', $validated['category_ids']))
                    );
                }

                if (array_key_exists('sections', $validated)) {
                    $this->deleteSectionGalleryUsages($draftVersion);
                    $draftVersion->sections()->delete();
                    $compiledHtml = [];

                    foreach ($validated['sections'] ?? [] as $index => $sec) {
                        $sortOrder = (int) ($sec['sort_order'] ?? $index);
                        $blockType = (string) ($sec['block_type'] ?? ($sec['type'] ?? 'text_area'));
                        $text = isset($sec['text']) ? (string) $sec['text'] : null;
                        $payload = is_array($sec['payload'] ?? null) ? $sec['payload'] : [];

                        $section = $draftVersion->sections()->create([
                            'block_type' => $blockType,
                            'sort_order' => $sortOrder,
                            'text' => $text,
                            'payload' => $payload,
                        ]);
                        $this->syncSectionGalleryUsages($section, $payload, $user?->id);

                        if ($blockType === 'heading' && $text) {
                            $level = in_array($payload['level'] ?? '', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $payload['level'] : (($payload['level'] ?? '') === 'normal' ? 'normal' : 'h2');
                            if ($level === 'normal') {
                                $compiledHtml[] = '<p>' . e($text) . '</p>';
                            } else {
                                $compiledHtml[] = "<{$level}>" . e($text) . "</{$level}>";
                            }
                        } elseif ($blockType === 'divider') {
                            $compiledHtml[] = '<hr class="article-divider">';
                        } elseif ($blockType === 'rich_text' && $text) {
                            $compiledHtml[] = '<div class="article-rich-text">' . $text . '</div>';
                        } elseif (in_array($blockType, ['text_area'], true) && $text) {
                            $compiledHtml[] = '<p>' . nl2br(e($text)) . '</p>';
                        } elseif ($blockType === 'image' && (($payload['mode'] ?? 'single') !== 'grid')) {
                            $src = $payload['src'] ?? ($payload['image']['src'] ?? '');
                            $alt = $payload['alt'] ?? ($payload['image']['alt'] ?? '');
                            $caption = $payload['caption'] ?? '';
                            $credit = $payload['credit'] ?? '';
                            $link = $payload['link'] ?? '';
                            if ($src) {
                                $img = '<img src="' . e($src) . '" alt="' . e($alt) . '">';
                                if ($link) {
                                    $img = '<a href="' . e($link) . '" target="_blank" rel="noopener noreferrer">' . $img . '</a>';
                                }
                                $compiledHtml[] = '<figure>' . $img . '<figcaption>' . e($caption) . ($credit ? ' (' . e($credit) . ')' : '') . '</figcaption></figure>';
                            }
                        } elseif (($blockType === 'image' && (($payload['mode'] ?? 'single') === 'grid')) || $blockType === 'multi_image') {
                            $images = is_array($payload['images'] ?? null) ? $payload['images'] : [];
                            $link = (string) ($payload['link'] ?? '');
                            $caption = (string) ($payload['caption'] ?? '');
                            $credit = (string) ($payload['credit'] ?? '');

                            $columns = (int) ($payload['columns'] ?? 2);
                            if ($columns < 2 || $columns > 4) {
                                $columns = 2;
                            }

                            $gridItems = '';
                            foreach ($images as $image) {
                                if (! is_array($image)) {
                                    continue;
                                }

                                $src = (string) ($image['src'] ?? '');
                                if ($src === '') {
                                    continue;
                                }

                                $alt = (string) ($image['alt'] ?? '');
                                $item = '<img src="' . e($src) . '" alt="' . e($alt) . '">';
                                if ($link !== '') {
                                    $item = '<a href="' . e($link) . '" target="_blank" rel="noopener noreferrer">' . $item . '</a>';
                                }

                                $gridItems .= $item;
                            }

                            if ($gridItems !== '') {
                                $figcaption = '';
                                if ($caption !== '' || $credit !== '') {
                                    $figcaption = '<figcaption>' . e($caption)
                                        . ($credit !== '' ? ' | ' . e($credit) : '')
                                        . '</figcaption>';
                                }

                                $compiledHtml[] = '<figure class="article-inline-media">'
                                    . '<div class="article-inline-media-grid" style="--grid-columns: ' . $columns . ';">'
                                    . $gridItems
                                    . '</div>'
                                    . $figcaption
                                    . '</figure>';
                            }
                        } elseif ($blockType === 'table') {
                            $columns = is_array($payload['columns'] ?? null) ? $payload['columns'] : [];
                            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
                            $tableCaption = (string) ($payload['caption'] ?? '');
                            $hasHeader = ($payload['has_header'] ?? true) !== false;

                            if ($columns !== [] || $rows !== []) {
                                $tableHtml = '<table>';
                                if ($hasHeader && $columns !== []) {
                                    $tableHtml .= '<thead><tr>';
                                    foreach ($columns as $column) {
                                        $tableHtml .= '<th>' . e((string) $column) . '</th>';
                                    }
                                    $tableHtml .= '</tr></thead>';
                                }
                                $tableHtml .= '<tbody>';
                                foreach ($rows as $row) {
                                    $tableHtml .= '<tr>';
                                    foreach ((array) $row as $cell) {
                                        $tableHtml .= '<td>' . e((string) $cell) . '</td>';
                                    }
                                    $tableHtml .= '</tr>';
                                }
                                $tableHtml .= '</tbody></table>';
                                if ($tableCaption !== '') {
                                    $tableHtml .= '<figcaption>' . e($tableCaption) . '</figcaption>';
                                }
                                $compiledHtml[] = '<figure>' . $tableHtml . '</figure>';
                            }
                        }
                    }

                }

                return $draftVersion->refresh();
            });

            return response()->json([
                'message' => 'Author draft updated successfully.',
                'data' => [
                    'id' => $draftVersion->id,
                    'sections' => $draftVersion->sections()->get(),
                ],
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to update author draft: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to update author draft.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function getEditorDetail(string $id): JsonResponse
    {
        $editorVersion = ArticleVersion::query()
            ->with([
                'authors',
                'categories',
                'createdBy',
                'bannerImage.gallery',
                'files',
                'sections.galleryUsages.gallery',
                'seo',
            ])
            ->where('id', $id)
            ->firstOrFail();

        $authors = User::query()
            ->orderBy('name')
            ->get()
            ->map(static function (User $user): array {
                return [
                    'text' => $user->name,
                    'value' => $user->id,
                ];
            })
            ->values()
            ->all();

        $categories = Category::query()
            ->where('is_deleted', 0)
            ->orderBy('seq_no')
            ->get()
            ->map(static function (Category $category): array {
                return [
                    'text' => $category->name,
                    'value' => $category->id,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => new EditorDetailResource($editorVersion),
            'authors' => $authors,
            'categories' => $categories,
        ]);
    }

    public function updateEditorReview(Request $request, string $id): JsonResponse
    {
        if (! $request->has('id')) {
            $request->merge(['id' => $id]);
        }

        $validated = $request->validate([
            'id' => ['nullable'],
            'title' => ['required', 'string', 'max:255'],
            'sub_title' => ['nullable', 'string', 'max:1000'],
            'slug' => ['required', 'string', 'max:255'],
            'report_from' => ['nullable', 'string', 'max:255'],
            'is_exclusive' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_breaking' => ['nullable', 'boolean'],
            'tags' => ['nullable'],
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
            'banner_image' => ['nullable', 'array'],
            'banner_image.id' => ['nullable'],
            'banner_image.gallery_id' => ['nullable'],
            'banner_image.alt' => ['nullable', 'string', 'max:1000'],
            'banner_image.caption' => ['nullable', 'string', 'max:2000'],
            'banner_image.photo_credit' => ['nullable', 'string', 'max:255'],
            'seo' => ['nullable', 'array'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $editorVersion = DB::transaction(function () use ($validated, $id, $user, $request): ArticleVersion {
                $editorVersion = ArticleVersion::query()
                    ->where('id', $id)
                    ->firstOrFail();

                $rawTags = $request->input('tags');
                $formattedTags = is_array($rawTags)
                    ? array_values(array_filter($rawTags))
                    : (is_string($rawTags) ? array_values(array_filter(array_map('trim', explode(',', $rawTags)))) : null);

                $editorVersion->fill([
                    'title' => $validated['title'],
                    'sub_title' => $validated['sub_title'] ?? null,
                    'slug' => $validated['slug'],
                    'report_from' => $validated['report_from'] ?? null,
                    'meta' => array_merge(is_array($editorVersion->meta) ? $editorVersion->meta : [], array_filter([
                        'is_exclusive' => (bool) ($request->input('is_exclusive', false)),
                        'is_featured' => (bool) ($request->input('is_featured', false)),
                        'is_breaking' => (bool) ($request->input('is_breaking', false)),
                        'tags' => $formattedTags ?? ($editorVersion->meta['tags'] ?? []),
                        'has_other_category' => (bool) ($request->input('has_other_category', false)),
                    ], static fn ($val) => $val !== null)),
                ]);
                $editorVersion->save();

                if (array_key_exists('banner_image', $validated)) {
                    $editorVersion->bannerImage()->delete();

                    $banner = $validated['banner_image'];
                    $galleryId = ! empty($banner['gallery_id']) ? (int) $banner['gallery_id'] : (! empty($banner['id']) ? (int) $banner['id'] : null);
                    if (is_array($banner) && $galleryId && Gallery::query()->where('id', $galleryId)->exists()) {
                        $editorVersion->bannerImage()->create([
                            'gallery_id' => $galleryId,
                            'usage_type' => 'article_versions',
                            'collection' => GalleryUsage::COLLECTION_BANNER,
                            'is_primary' => true,
                            'alt_text' => $banner['alt'] ?? null,
                            'caption' => $banner['caption'] ?? null,
                            'photo_credit' => $banner['photo_credit'] ?? null,
                            'created_by_id' => $user->id,
                        ]);
                    }
                }

                if ($request->has('seo') && is_array($request->input('seo'))) {
                    $seoData = $request->input('seo');
                    $articleVersionSeo = $editorVersion->seo()->firstOrNew([]);
                    $canonicalUrl = $seoData['canonical_url'] ?? (rtrim((string) config('app.url'), '/') . '/' . ltrim((string) $editorVersion->slug, '/'));

                    $ogImage = is_array($seoData['og']['image'] ?? null)
                        ? ($seoData['og']['image']['url'] ?? null)
                        : ($seoData['og']['image'] ?? null);

                    $twitterImage = is_array($seoData['twitter']['image'] ?? null)
                        ? ($seoData['twitter']['image']['url'] ?? null)
                        : ($seoData['twitter']['image'] ?? null);

                    $articleVersionSeo->fill([
                        'meta_title' => $seoData['meta_title'] ?? $editorVersion->title,
                        'meta_descriptions' => $seoData['meta_description'] ?? null,
                        'og_title' => $seoData['og']['title'] ?? $seoData['meta_title'] ?? $editorVersion->title,
                        'og_description' => $seoData['og']['description'] ?? $seoData['meta_description'] ?? null,
                        'og_image_url' => $ogImage,
                        'twitter_title' => $seoData['twitter']['title'] ?? $seoData['meta_title'] ?? $editorVersion->title,
                        'twitter_description' => $seoData['twitter']['description'] ?? $seoData['meta_description'] ?? null,
                        'twitter_image_url' => $twitterImage,
                        'focus_keywords' => is_array($seoData['focus_keywords'] ?? null) ? implode(', ', $seoData['focus_keywords']) : ($seoData['focus_keywords'] ?? null),
                        'canonical_url' => $canonicalUrl,
                    ]);
                    $articleVersionSeo->save();
                }

                if (isset($validated['author_ids'])) {
                    $editorVersion->authors()->sync(
                        array_values(array_map('intval', $validated['author_ids']))
                    );
                }

                if (isset($validated['category_ids'])) {
                    $editorVersion->categories()->sync(
                        array_values(array_map('intval', $validated['category_ids']))
                    );
                }

                if (array_key_exists('sections', $validated)) {
                    $this->deleteSectionGalleryUsages($editorVersion);
                    $editorVersion->sections()->delete();
                    $compiledHtml = [];

                    foreach ($validated['sections'] ?? [] as $index => $sec) {
                        $sortOrder = (int) ($sec['sort_order'] ?? $index);
                        $blockType = (string) ($sec['block_type'] ?? ($sec['type'] ?? 'text_area'));
                        $text = isset($sec['text']) ? (string) $sec['text'] : null;
                        $payload = is_array($sec['payload'] ?? null) ? $sec['payload'] : [];

                        $section = $editorVersion->sections()->create([
                            'block_type' => $blockType,
                            'sort_order' => $sortOrder,
                            'text' => $text,
                            'payload' => $payload,
                        ]);
                        $this->syncSectionGalleryUsages($section, $payload, $user->id);

                        if ($blockType === 'heading' && $text) {
                            $level = in_array($payload['level'] ?? '', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $payload['level'] : (($payload['level'] ?? '') === 'normal' ? 'normal' : 'h2');
                            if ($level === 'normal') {
                                $compiledHtml[] = '<p>' . e($text) . '</p>';
                            } else {
                                $compiledHtml[] = "<{$level}>" . e($text) . "</{$level}>";
                            }
                        } elseif ($blockType === 'divider') {
                            $compiledHtml[] = '<hr class="article-divider">';
                        } elseif ($blockType === 'rich_text' && $text) {
                            $compiledHtml[] = '<div class="article-rich-text">' . $text . '</div>';
                        } elseif (in_array($blockType, ['text_area'], true) && $text) {
                            $compiledHtml[] = '<p>' . nl2br(e($text)) . '</p>';
                        } elseif ($blockType === 'image' && (($payload['mode'] ?? 'single') !== 'grid')) {
                            $src = $payload['src'] ?? ($payload['image']['src'] ?? '');
                            $alt = $payload['alt'] ?? ($payload['image']['alt'] ?? '');
                            $caption = $payload['caption'] ?? '';
                            $credit = $payload['credit'] ?? '';
                            $link = $payload['link'] ?? '';
                            if ($src) {
                                $img = '<img src="' . e($src) . '" alt="' . e($alt) . '">';
                                if ($link) {
                                    $img = '<a href="' . e($link) . '" target="_blank" rel="noopener noreferrer">' . $img . '</a>';
                                }
                                $compiledHtml[] = '<figure>' . $img . '<figcaption>' . e($caption) . ($credit ? ' (' . e($credit) . ')' : '') . '</figcaption></figure>';
                            }
                        } elseif (($blockType === 'image' && (($payload['mode'] ?? 'single') === 'grid')) || $blockType === 'multi_image') {
                            $images = is_array($payload['images'] ?? null) ? $payload['images'] : [];
                            $link = (string) ($payload['link'] ?? '');
                            $caption = (string) ($payload['caption'] ?? '');
                            $credit = (string) ($payload['credit'] ?? '');

                            $columns = (int) ($payload['columns'] ?? 2);
                            if ($columns < 2 || $columns > 4) {
                                $columns = 2;
                            }

                            $gridItems = '';
                            foreach ($images as $image) {
                                if (! is_array($image)) {
                                    continue;
                                }

                                $src = (string) ($image['src'] ?? '');
                                if ($src === '') {
                                    continue;
                                }

                                $alt = (string) ($image['alt'] ?? '');
                                $item = '<img src="' . e($src) . '" alt="' . e($alt) . '">';
                                if ($link !== '') {
                                    $item = '<a href="' . e($link) . '" target="_blank" rel="noopener noreferrer">' . $item . '</a>';
                                }

                                $gridItems .= $item;
                            }

                            if ($gridItems !== '') {
                                $figcaption = '';
                                if ($caption !== '' || $credit !== '') {
                                    $figcaption = '<figcaption>' . e($caption)
                                        . ($credit !== '' ? ' | ' . e($credit) : '')
                                        . '</figcaption>';
                                }

                                $compiledHtml[] = '<figure class="article-inline-media">'
                                    . '<div class="article-inline-media-grid" style="--grid-columns: ' . $columns . ';">'
                                    . $gridItems
                                    . '</div>'
                                    . $figcaption
                                    . '</figure>';
                            }
                        } elseif ($blockType === 'table') {
                            $columns = is_array($payload['columns'] ?? null) ? $payload['columns'] : [];
                            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
                            $tableCaption = (string) ($payload['caption'] ?? '');
                            $hasHeader = ($payload['has_header'] ?? true) !== false;

                            if ($columns !== [] || $rows !== []) {
                                $tableHtml = '<table>';
                                if ($hasHeader && $columns !== []) {
                                    $tableHtml .= '<thead><tr>';
                                    foreach ($columns as $column) {
                                        $tableHtml .= '<th>' . e((string) $column) . '</th>';
                                    }
                                    $tableHtml .= '</tr></thead>';
                                }
                                $tableHtml .= '<tbody>';
                                foreach ($rows as $row) {
                                    $tableHtml .= '<tr>';
                                    foreach ((array) $row as $cell) {
                                        $tableHtml .= '<td>' . e((string) $cell) . '</td>';
                                    }
                                    $tableHtml .= '</tr>';
                                }
                                $tableHtml .= '</tbody></table>';
                                if ($tableCaption !== '') {
                                    $tableHtml .= '<figcaption>' . e($tableCaption) . '</figcaption>';
                                }
                                $compiledHtml[] = '<figure>' . $tableHtml . '</figure>';
                            }
                        }
                    }

                }

                return $editorVersion->refresh();
            });

            return response()->json([
                'message' => 'Editor review updated successfully.',
                'data' => [
                    'id' => $editorVersion->id,
                    'submitted_at' => $editorVersion->submitted_at?->toISOString(),
                    'author_ids' => $editorVersion->authors->pluck('id')->values()->all(),
                    'category_ids' => $editorVersion->categories->pluck('id')->values()->all(),
                ],
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to update editor review: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to update editor review.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function updateSeo(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required'],
            'slug' => ['required', 'string', 'max:255'],
            'meta_title' => ['required', 'string', 'max:255'],
            'meta_description' => ['required', 'string', 'max:500'],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:2048'],
            'twitter_title' => ['nullable', 'string', 'max:255'],
            'twitter_description' => ['nullable', 'string', 'max:500'],
            'twitter_image' => ['nullable', 'string', 'max:2048'],
            'focus_keywords' => ['nullable', 'string', 'max:1000'],
            'canonical_url' => ['nullable', 'string', 'max:2048'],
            'json_ld' => ['nullable', 'json'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $articleVersionSeo = DB::transaction(function () use ($validated, $id): ArticleVersionSeo {
                $articleVersion = ArticleVersion::query()
                    ->where('id', $id)
                    ->firstOrFail();

                $articleVersion->forceFill([
                    'slug' => $validated['slug'],
                ])->save();

                $canonicalUrl = rtrim((string) config('app.url'), '/') . '/' . ltrim((string) $articleVersion->slug, '/');

                $articleVersionSeo = $articleVersion->seo()->firstOrNew([]);

                $articleVersionSeo->fill([
                    'meta_title' => $validated['meta_title'],
                    'meta_descriptions' => $validated['meta_description'],
                    'og_title' => $validated['og_title'] ?? null,
                    'og_description' => $validated['og_description'] ?? null,
                    'og_image_url' => $validated['og_image'] ?? null,
                    'twitter_title' => $validated['twitter_title'] ?? null,
                    'twitter_description' => $validated['twitter_description'] ?? null,
                    'twitter_image_url' => $validated['twitter_image'] ?? null,
                    'focus_keywords' => $validated['focus_keywords'] ?? null,
                    'canonical_url' => $canonicalUrl,
                    'json_ld' => isset($validated['json_ld']) ? json_decode($validated['json_ld'], true, 512, JSON_THROW_ON_ERROR) : null,
                ]);
                $articleVersionSeo->save();

                return $articleVersionSeo->refresh();
            });

            return response()->json([
                'message' => 'SEO updated successfully.',
                'data' => $articleVersionSeo,
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to update SEO: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to update SEO.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function getSubmittedDetail(string $id): JsonResponse
    {
        $submittedVersion = ArticleVersion::query()
            ->with([
                'authors',
                'categories',
                'sections.galleryUsages.gallery',
                'createdBy',
                'bannerImage.gallery',
                'files',
                'publishedBy',
                'assignedTo',
            ])
            ->where('id', $id)
            ->where('status', ArticleVersion::STATUS_REVIEW_REQUEST)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $submittedVersion->id,
                'article_id' => $submittedVersion->article_id,
                'article_version_id' => $submittedVersion->id,
                'version_type' => $submittedVersion->version_type,
                'status' => $submittedVersion->status,
                'title' => $submittedVersion->title,
                'sub_title' => $submittedVersion->sub_title,
                'slug' => $submittedVersion->slug,
                'report_from' => $submittedVersion->report_from,
                'author_ids' => $submittedVersion->authors->pluck('id')->values()->all(),
                'authors' => $submittedVersion->authors->map(static fn($author): array => [
                    'id' => $author->id,
                    'name' => $author->name,
                    'slug' => $author->slug,
                    'email' => $author->email,
                ])->values()->all(),
                'category_ids' => $submittedVersion->categories->pluck('id')->values()->all(),
                'categories' => $submittedVersion->categories->map(static fn($category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])->values()->all(),
                'sections' => $submittedVersion->sections->map(static fn($section): array => EditorDetailResource::sectionPayload($section))->values()->all(),
                'created_by' => $submittedVersion->createdBy ? [
                    'id' => $submittedVersion->createdBy->id,
                    'name' => $submittedVersion->createdBy->name,
                ] : null,
                'published_by' => $submittedVersion->publishedBy ? [
                    'id' => $submittedVersion->publishedBy->id,
                    'name' => $submittedVersion->publishedBy->name,
                    'email' => $submittedVersion->publishedBy->email,
                ] : null,
                'assigned_to' => $submittedVersion->assignedTo ? [
                    'id' => $submittedVersion->assignedTo->id,
                    'name' => $submittedVersion->assignedTo->name,
                    'email' => $submittedVersion->assignedTo->email,
                ] : null,
                'published_at' => $submittedVersion->published_at,
                'submitted_at' => $submittedVersion->submitted_at,
                'banner_image' => $submittedVersion->bannerImage ? [
                    'id' => $submittedVersion->bannerImage->id,
                    'url' => $submittedVersion->bannerImage->gallery?->url,
                    'alt_text' => $submittedVersion->bannerImage->alt_text,
                    'photo_credit' => $submittedVersion->bannerImage->photo_credit,
                    'caption' => $submittedVersion->bannerImage->caption,
                ] : null,
                'files' => $submittedVersion->files()->get()->map(static function ($file): array {
                    return [
                        'id' => $file->id,
                        'type' => $file->mime_type,
                        'filename' => $file->filename,
                        'url' => $file?->url,
                        'size' => CustomHelper::humanFileSize((int) $file->size),
                    ];
                })->all(),
            ],
        ]);
    }

    public function assignArticleVersion(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'assigned_to_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $articleVersion = DB::transaction(function () use ($id, $validated, $user): ArticleVersion {
                $articleVersion = ArticleVersion::query()
                    ->where('id', $id)
                    ->where('status', ArticleVersion::STATUS_REVIEW_REQUEST)
                    ->firstOrFail();

                $articleVersion->forceFill([
                    'status' => ArticleVersion::STATUS_IN_REVIEW,
                    'assigned_by_id' => $user->id,
                    'assigned_to_id' => (int) $validated['assigned_to_id'],
                ])->save();

                $articleVersion->article?->forceFill([
                    'status' => Article::STATUS_IN_REVIEW,
                    'latest_version_id' => $articleVersion->id,
                ])->save();

                return $articleVersion->refresh();
            });

            return response()->json([
                'message' => 'Article assigned successfully.',
                'data' => [
                    'id' => $articleVersion->id,
                ],
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to assign article version: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to assign article version.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function publishArticle(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $publishedVersion = DB::transaction(function () use ($id, $user): ArticleVersion {
                $publishedVersion = ArticleVersion::query()
                    ->where('id', $id)
                    ->firstOrFail();

                $publishedAt = now();

                $publishedVersion->forceFill([
                    'status' => ArticleVersion::STATUS_PUBLISHED,
                    'published_by_id' => $user->id,
                    'published_at' => $publishedAt,
                ])->save();

                $article = $publishedVersion->article;
                if ($article) {
                    $seo = $publishedVersion->seo;
                    if ($seo && is_array($seo->json_ld)) {
                        $seo->forceFill([
                            'json_ld' => array_merge($seo->json_ld, [
                                'datePublished' => $publishedAt->toISOString(),
                                'dateModified' => $publishedAt->toISOString(),
                            ]),
                        ])->save();
                    }

                    $article->forceFill([
                        'status' => Article::STATUS_PUBLISHED,
                        'latest_version_id' => $publishedVersion->id,
                        'published_version_id' => $publishedVersion->id,
                        'first_published_at' => $article->first_published_at ?? $publishedAt,
                        'last_published_at' => $publishedAt,
                    ])->save();
                }

                return $publishedVersion->refresh();
            });

            $publishedVersion->loadMissing(['authors', 'categories']);
            NextRevalidator::revalidate(NextRevalidator::articleTags($publishedVersion));

            return response()->json([
                'message' => 'Article published successfully.',
                'data' => [
                    'id' => $publishedVersion->id,
                ],
                'success' => true,
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to publish article: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to publish article.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }
}
