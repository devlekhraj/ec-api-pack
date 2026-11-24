<?php

declare(strict_types=1);

namespace EcApi\Domains\Article\Controllers;

use EcApi\Domains\Article\Resources\EditorDetailResource;
use EcApi\Models\ArticleVersion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArticleListController extends Controller
{
    public function myArticlesList(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'published');

        return match ($type) {
            'all' => $this->allAccessibleList($request),
            'draft', 'drafts' => $this->draftList($request),
            'review_request' => $this->underReviewList($request, ArticleVersion::STATUS_REVIEW_REQUEST),
            'in_review' => $this->underReviewList($request, ArticleVersion::STATUS_IN_REVIEW),
            'assigned', 'assigned_to_me' => $this->assignedList($request),
            default => $this->publishedList($request),
        };
    }

    public function allAccessibleList(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPageParam = (int) $request->query('per_page', 15);
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $query = $this->baseQuery()
            ->where(function ($builder) use ($user): void {
                $builder
                    ->where(function ($published): void {
                        $published->where('status', ArticleVersion::STATUS_PUBLISHED)
                            ->where(function ($current): void {
                                $current->whereDoesntHave('article')
                                    ->orWhereHas('article', function ($articleQuery): void {
                                        $articleQuery->whereNotNull('published_version_id')
                                            ->whereColumn('articles.published_version_id', 'article_versions.id');
                                    });
                            });
                    });

                $builder->orWhere(function ($ownDraftsAndSubmissions) use ($user): void {
                    $ownDraftsAndSubmissions
                        ->where('created_by_id', $user->id)
                        ->whereIn('status', [
                            ArticleVersion::STATUS_DRAFT,
                            ArticleVersion::STATUS_REVIEW_REQUEST,
                            ArticleVersion::STATUS_IN_REVIEW,
                        ]);
                });

                $builder->orWhere(function ($assigned) use ($user): void {
                    $assigned
                        ->where('assigned_to_id', $user->id)
                        ->where('status', ArticleVersion::STATUS_IN_REVIEW);
                });
            });

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%')
                    ->orWhereHas('authors', function ($authorQuery) use ($search): void {
                        $authorQuery->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('createdBy', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('assignedBy', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('assignedTo', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        $query->reorder()->orderByDesc('updated_at')->orderByDesc('created_at');

        $paginator = null;
        if ($perPageParam === -1) {
            $items = $query->get();
        } else {
            $perPage = max(1, min($perPageParam, 100));
            $paginator = $query->paginate($perPage);
            $items = $paginator->getCollection();
        }

        $mapItem = static function (ArticleVersion $version) use ($user): array {
            $isDraft = (string) $version->status === ArticleVersion::STATUS_DRAFT;

            return [
                'id' => $version->id,
                'title' => (string) ($version->title ?? '-'),
                'slug' => (string) ($version->slug ?? ''),
                'status' => (string) ($version->status ?? ''),
                'version_type' => (string) ($version->version_type ?? ArticleVersion::TYPE_ORIGINAL),
                'revisions_count' => 0,
                'created_at' => $version->created_at?->toDateTimeString(),
                'submitted_at' => $version->submitted_at?->toDateTimeString(),
                'published_at' => ($version->published_at ?? $version->article?->last_published_at ?? $version->article?->first_published_at)?->toDateTimeString(),
                'created_by' => $version->createdBy?->name,
                'assigned_by' => $version->assignedBy?->name,
                'assigned_to' => $version->assignedTo?->name,
                'thumb' => $version->bannerImage?->gallery?->url ?? $version->bannerImage?->url,
                'versions' => [[
                    'id' => $version->id,
                    'version_number' => 1,
                    'version_type' => (string) ($version->version_type ?? ArticleVersion::TYPE_ORIGINAL),
                    'title' => (string) ($version->title ?? '-'),
                    'status' => (string) ($version->status ?? ''),
                    'is_current' => (string) $version->status === ArticleVersion::STATUS_PUBLISHED,
                    'created_by_id' => $version->created_by_id,
                    'created_by' => $version->createdBy?->name ?? 'Unknown',
                    'is_own_draft' => $isDraft && (int) $version->created_by_id === (int) $user->id,
                    'published_at' => $version->published_at?->toDateTimeString(),
                    'created_at' => $version->created_at?->toDateTimeString(),
                    'published_by' => $version->publishedBy?->name ?? $version->createdBy?->name,
                ]],
            ];
        };

        if ($paginator === null) {
            $itemsCollection = collect($items);

            return response()->json([
                'data' => $itemsCollection->map($mapItem)->values()->all(),
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $itemsCollection->count(),
                    'total' => $itemsCollection->count(),
                    'last_page' => 1,
                    'from' => $itemsCollection->count() > 0 ? 1 : null,
                    'to' => $itemsCollection->count() > 0 ? $itemsCollection->count() : null,
                ],
            ]);
        }

        return response()->json([
            'data' => collect($paginator->getCollection())->map($mapItem)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function draftList(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPageParam = (int) $request->query('per_page', 15);
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $query = $this->baseQuery()
            ->where('created_by_id', $user->id)
            ->where('status', ArticleVersion::STATUS_DRAFT);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%')
                    ->orWhereHas('authors', function ($authorQuery) use ($search): void {
                        $authorQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        $paginator = null;
        if ($perPageParam === -1) {
            $items = $query->get();
        } else {
            $perPage = max(1, min($perPageParam, 100));
            $paginator = $query->paginate($perPage);
            $items = $paginator->getCollection();
        }

        $itemsCollection = collect($items);
        $articleIds = $itemsCollection->pluck('article_id')->filter()->unique()->values()->all();
        $fallbackIds = $itemsCollection->whereNull('article_id')->map(static fn($v) => $v->original_version_id ?? $v->id)->filter()->unique()->values()->all();

        $allVersions = ArticleVersion::query()
            ->with(['publishedBy', 'createdBy', 'article'])
            ->where(static function ($b) use ($articleIds, $fallbackIds): void {
                $hasArticle = ! empty($articleIds);
                $hasFallback = ! empty($fallbackIds);

                if ($hasArticle && $hasFallback) {
                    $b->whereIn('article_id', $articleIds)
                      ->orWhere(static function ($b2) use ($fallbackIds): void {
                          $b2->whereNull('article_id')
                             ->where(static function ($b3) use ($fallbackIds): void {
                                 $b3->whereIn('original_version_id', $fallbackIds)
                                    ->orWhereIn('id', $fallbackIds);
                             });
                      });
                } elseif ($hasArticle) {
                    $b->whereIn('article_id', $articleIds);
                } elseif ($hasFallback) {
                    $b->whereNull('article_id')
                      ->where(static function ($b2) use ($fallbackIds): void {
                          $b2->whereIn('original_version_id', $fallbackIds)
                             ->orWhereIn('id', $fallbackIds);
                      });
                }
            })
            ->orderBy('id', 'asc')
            ->get();

        $mapItem = static function (ArticleVersion $version) use ($allVersions, $user): array {
            if ($version->article_id !== null) {
                $related = $allVersions->where('article_id', $version->article_id);
            } else {
                $key = $version->original_version_id ?? $version->id;
                $related = $allVersions->filter(static fn($v) =>
                    $v->article_id === null && ($v->id === $key || $v->original_version_id === $key)
                );
            }

            $articleRecord = $version->article ?? $related->first(static fn($v) => $v->article !== null)?->article;
            $publishedVersionId = $articleRecord?->published_version_id;

            $sortedRelated = $related->sortBy('id')->values();
            $versionsList = [];
            $vNum = 1;
            foreach ($sortedRelated as $relV) {
                $isDraft = (string) $relV->status === ArticleVersion::STATUS_DRAFT;
                $isOwnDraft = $isDraft && $user && (int) $relV->created_by_id === (int) $user->id;
                $creatorName = $relV->createdBy?->name ?? 'Unknown';

                $isCurrent = $publishedVersionId !== null
                    && (int) $relV->id === (int) $publishedVersionId
                    && (string) $relV->status === ArticleVersion::STATUS_PUBLISHED;

                $versionsList[] = [
                    'id'             => $relV->id,
                    'version_number' => $vNum++,
                    'version_type'   => (string) ($relV->version_type ?? ArticleVersion::TYPE_ORIGINAL),
                    'title'          => (string) ($relV->title ?? '-'),
                    'status'         => (string) ($relV->status ?? ''),
                    'is_current'     => $isCurrent,
                    'created_by_id'  => $relV->created_by_id,
                    'created_by'     => $creatorName,
                    'is_own_draft'   => $isOwnDraft,
                    'published_at'   => $relV->published_at?->toDateTimeString(),
                    'created_at'     => $relV->created_at?->toDateTimeString(),
                    'published_by'   => $relV->publishedBy?->name ?? $relV->createdBy?->name,
                ];
            }

            // Descending order so newest version is at the top of the menu
            $versionsList = array_reverse($versionsList);
            $revisionsCount = $related->where('version_type', ArticleVersion::TYPE_REVISION)->count();

            return [
                'id'              => $version->id,
                'title'           => (string) ($version->title ?? '-'),
                'slug'            => (string) ($version->slug ?? ''),
                'version_type'    => (string) ($version->version_type ?? ArticleVersion::TYPE_ORIGINAL),
                'revisions_count' => $revisionsCount,
                'status'          => $version->status,
                'created_at'      => $version->created_at,
                'thumb'           => $version->bannerImage?->gallery?->url ?? $version->bannerImage?->url,
                'versions'        => $versionsList,
            ];
        };

        if ($paginator === null) {
            return response()->json([
                'data' => $itemsCollection->map($mapItem)->values()->all(),
                'meta' => [
                    'current_page' => 1,
                    'per_page'     => $itemsCollection->count(),
                    'total'        => $itemsCollection->count(),
                    'last_page'    => 1,
                    'from'         => $itemsCollection->count() > 0 ? 1 : null,
                    'to'           => $itemsCollection->count() > 0 ? $itemsCollection->count() : null,
                ],
            ]);
        }

        return response()->json([
            'data' => collect($paginator->getCollection())->map($mapItem)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }

    public function underReviewList(Request $request, ?string $specificStatus = null): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPageParam = (int) $request->query('per_page', 15);
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $query = $this->baseQuery()
            ->with([
                'assignedTo',
            ])
            ->where('created_by_id', $user->id);

        if ($specificStatus !== null) {
            $query->where('status', $specificStatus);
        } else {
            $query->whereIn('status', [
                ArticleVersion::STATUS_REVIEW_REQUEST,
                ArticleVersion::STATUS_IN_REVIEW,
            ]);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%')
                    ->orWhereHas('authors', function ($authorQuery) use ($search): void {
                        $authorQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        if ($perPageParam === -1) {
            $items = $query->get();

            return response()->json([
                'data' => collect($items)->map(static function (ArticleVersion $version): array {

                    return [
                        'id' => $version->id,
                        'title' => (string) ($version->title ?? '-'),
                        'slug' => (string) ($version->slug ?? ''),
                        'submitted_at' => $version->submitted_at,
                        'assigned_to' => $version->assignedTo?->name,
                        'thumb' => $version->bannerImage?->gallery?->url ?? $version->bannerImage?->url,
                        'version_type' => $version->version_type,
                        'status' => $version->status,
                    ];
                })->values()->all(),
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $items->count(),
                    'total' => $items->count(),
                    'last_page' => 1,
                    'from' => $items->count() > 0 ? 1 : null,
                    'to' => $items->count() > 0 ? $items->count() : null,
                ],
            ]);
        }

        $perPage = max(1, min($perPageParam, 100));
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->getCollection())->map(static function (ArticleVersion $version): array {
                return [
                    'id' => $version->id,
                    'title' => (string) ($version->title ?? '-'),
                    'slug' => (string) ($version->slug ?? ''),
                    'submitted_at' => $version->submitted_at,
                    'assigned_to' => $version->assignedTo?->name,
                    'thumb' => $version->bannerImage?->gallery?->url ?? $version->bannerImage?->url,
                    'version_type' => $version->version_type,
                    'status' => $version->status,
                ];
            })->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function assignedList(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPageParam = (int) $request->query('per_page', 15);
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $query = $this->baseQuery()
            ->with([
                'createdBy',
                'assignedBy',
                'assignedTo',
            ])
            ->where('assigned_to_id', $user->id)
            ->where('status', ArticleVersion::STATUS_IN_REVIEW);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%')
                    ->orWhereHas('createdBy', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('assignedBy', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('assignedTo', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        $mapItem = static function (ArticleVersion $version): array {
            return [
                'id' => $version->id,
                'title' => (string) ($version->title ?? '-'),
                'slug' => (string) ($version->slug ?? ''),
                'created_by' => $version->createdBy?->name,
                'submitted_at' => $version->submitted_at?->toDateTimeString(),
                'assigned_by' => $version->assignedBy?->name,
                'assigned_to' => $version->assignedTo?->name,
                'thumb' => $version->bannerImage?->gallery?->url ?? $version->bannerImage?->url,
                'status' => $version->status,
            ];
        };

        if ($perPageParam === -1) {
            $items = $query->get();

            return response()->json([
                'data' => collect($items)->map($mapItem)->values()->all(),
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $items->count(),
                    'total' => $items->count(),
                    'last_page' => 1,
                    'from' => $items->count() > 0 ? 1 : null,
                    'to' => $items->count() > 0 ? $items->count() : null,
                ],
            ]);
        }

        $perPage = max(1, min($perPageParam, 100));
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->getCollection())->map($mapItem)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function publishedList(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $authorId = $request->query('author_id');
        $categoryId = $request->query('category_id');
        $publishedDate = trim((string) $request->query('published_date', ''));
        $perPageParam = (int) $request->query('per_page', 15);
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $query = $this->baseQuery()
            ->with([
                'publishedBy',
                'createdBy',
            ])
            ->where('status', ArticleVersion::STATUS_PUBLISHED)
            ->where(function ($builder): void {
                $builder->whereDoesntHave('article')
                    ->orWhereHas('article', function ($articleQuery): void {
                        $articleQuery->whereNotNull('published_version_id')
                            ->whereColumn('articles.published_version_id', 'article_versions.id');
                    });
            })
            ->where(function ($builder): void {
                $builder->whereDoesntHave('article', function ($articleQuery): void {
                    $articleQuery->whereNotNull('published_version_id')
                        ->whereColumn('articles.published_version_id', '!=', 'article_versions.id');
                });
            });

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%')
                    ->orWhereHas('authors', function ($authorQuery) use ($search): void {
                        $authorQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        if ($authorId !== null && $authorId !== '') {
            $query->whereHas('authors', function ($authorQuery) use ($authorId): void {
                $authorQuery->where('users.id', (int) $authorId);
            });
        }

        if ($categoryId !== null && $categoryId !== '') {
            $query->whereHas('categories', function ($categoryQuery) use ($categoryId): void {
                $categoryQuery->where('categories.id', (int) $categoryId);
            });
        }

        if ($publishedDate !== '') {
            $query->where(function ($builder) use ($publishedDate): void {
                $builder->whereDate('published_at', $publishedDate)
                    ->orWhereHas('article', function ($articleQuery) use ($publishedDate): void {
                        $articleQuery->whereDate('last_published_at', $publishedDate)
                            ->orWhereDate('first_published_at', $publishedDate);
                    });
            });
        }

        $query->select('article_versions.*')
            ->selectSub(
                ArticleVersion::query()
                    ->from('article_versions as av_rev')
                    ->where(function ($builder): void {
                        $builder->where(function ($b): void {
                            $b->whereNotNull('article_versions.article_id')
                                ->whereColumn('av_rev.article_id', 'article_versions.article_id');
                        })->orWhere(function ($b): void {
                            $b->whereNull('article_versions.article_id')
                                ->where(function ($b2): void {
                                    $b2->whereColumn('av_rev.original_version_id', 'article_versions.id')
                                        ->orWhere(function ($b3): void {
                                            $b3->whereNotNull('article_versions.original_version_id')
                                                ->whereColumn('av_rev.original_version_id', 'article_versions.original_version_id');
                                        });
                                });
                        });
                    })
                    ->where('av_rev.version_type', ArticleVersion::TYPE_REVISION)
                    ->selectRaw('count(*)'),
                'revisions_count'
            );

        $query->reorder()->orderByDesc('id');

        $paginator = null;
        if ($perPageParam === -1) {
            $items = $query->get();
        } else {
            $perPage = max(1, min($perPageParam, 100));
            $paginator = $query->paginate($perPage);
            $items = $paginator->getCollection();
        }

        $itemsCollection = collect($items);
        $articleIds = $itemsCollection->pluck('article_id')->filter()->unique()->values()->all();
        $fallbackIds = $itemsCollection->whereNull('article_id')->map(static fn($v) => $v->original_version_id ?? $v->id)->filter()->unique()->values()->all();

        $allVersions = ArticleVersion::query()
            ->with(['publishedBy', 'createdBy', 'article'])
            ->where(static function ($b) use ($articleIds, $fallbackIds): void {
                $hasArticle = ! empty($articleIds);
                $hasFallback = ! empty($fallbackIds);

                if ($hasArticle && $hasFallback) {
                    $b->whereIn('article_id', $articleIds)
                      ->orWhere(static function ($b2) use ($fallbackIds): void {
                          $b2->whereNull('article_id')
                             ->where(static function ($b3) use ($fallbackIds): void {
                                 $b3->whereIn('original_version_id', $fallbackIds)
                                    ->orWhereIn('id', $fallbackIds);
                             });
                      });
                } elseif ($hasArticle) {
                    $b->whereIn('article_id', $articleIds);
                } elseif ($hasFallback) {
                    $b->whereNull('article_id')
                      ->where(static function ($b2) use ($fallbackIds): void {
                          $b2->whereIn('original_version_id', $fallbackIds)
                             ->orWhereIn('id', $fallbackIds);
                      });
                }
            })
            ->orderBy('id', 'asc')
            ->get();

        $mapItem = static function (ArticleVersion $version) use ($allVersions, $user): array {
            if ($version->article_id !== null) {
                $related = $allVersions->where('article_id', $version->article_id);
            } else {
                $key = $version->original_version_id ?? $version->id;
                $related = $allVersions->filter(static fn($v) =>
                    $v->article_id === null && ($v->id === $key || $v->original_version_id === $key)
                );
            }

            $articleRecord = $version->article ?? $related->first(static fn($v) => $v->article !== null)?->article;
            $publishedVersionId = $articleRecord?->published_version_id ?? ($version->status === ArticleVersion::STATUS_PUBLISHED ? $version->id : null);

            $sortedRelated = $related->sortBy('id')->values();
            $versionsList = [];
            $vNum = 1;
            foreach ($sortedRelated as $relV) {
                $isDraft = (string) $relV->status === ArticleVersion::STATUS_DRAFT;
                $isOwnDraft = $isDraft && $user && (int) $relV->created_by_id === (int) $user->id;
                $creatorName = $relV->createdBy?->name ?? 'Unknown';

                $isCurrent = $publishedVersionId !== null
                    && (int) $relV->id === (int) $publishedVersionId
                    && (string) $relV->status === ArticleVersion::STATUS_PUBLISHED;

                $versionsList[] = [
                    'id'             => $relV->id,
                    'version_number' => $vNum++,
                    'version_type'   => (string) ($relV->version_type ?? ArticleVersion::TYPE_ORIGINAL),
                    'title'          => (string) ($relV->title ?? '-'),
                    'status'         => (string) ($relV->status ?? ''),
                    'is_current'     => $isCurrent,
                    'created_by_id'  => $relV->created_by_id,
                    'created_by'     => $creatorName,
                    'is_own_draft'   => $isOwnDraft,
                    'published_at'   => $relV->published_at?->toDateTimeString(),
                    'created_at'     => $relV->created_at?->toDateTimeString(),
                    'published_by'   => $relV->publishedBy?->name ?? $relV->createdBy?->name,
                ];
            }

            // Descending order so newest version is at the top of the menu
            $versionsList = array_reverse($versionsList);

            return [
                'id'              => $version->id,
                'title'           => (string) ($version->title ?? '-'),
                'slug'            => (string) ($version->slug ?? ''),
                'status'          => $version->status === ArticleVersion::STATUS_PUBLISHED,
                'version_type'    => (string) ($version->version_type ?? ArticleVersion::TYPE_ORIGINAL),
                'revisions_count' => (int) ($version->revisions_count ?? 0),
                'published_at'    => ($version->published_at ?? $version->article?->last_published_at ?? $version->article?->first_published_at)?->toDateTimeString(),
                'published_by'    => $version->publishedBy?->name ?? $version->createdBy?->name,
                'thumb'           => $version->bannerImage?->gallery?->url,
                'versions'        => $versionsList,
            ];
        };

        if ($paginator === null) {
            return response()->json([
                'data' => $itemsCollection->map($mapItem)->values()->all(),
                'meta' => [
                    'current_page' => 1,
                    'per_page'     => $itemsCollection->count(),
                    'total'        => $itemsCollection->count(),
                    'last_page'    => 1,
                    'from'         => $itemsCollection->count() > 0 ? 1 : null,
                    'to'           => $itemsCollection->count() > 0 ? $itemsCollection->count() : null,
                ],
            ]);
        }

        return response()->json([
            'data' => collect($paginator->getCollection())->map($mapItem)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }

    public function publishedArticleVersions(Request $request, $articleId): JsonResponse
    {
        $user = $request->user();
        $version = ArticleVersion::query()->with('article')->findOrFail($articleId);

        $query = ArticleVersion::query()->with(['publishedBy', 'createdBy', 'article']);
        if ($version->article_id !== null) {
            $query->where('article_id', $version->article_id);
        } else {
            $key = $version->original_version_id ?? $version->id;
            $query->whereNull('article_id')
                ->where(static function ($b) use ($key): void {
                    $b->where('id', $key)->orWhere('original_version_id', $key);
                });
        }

        $allVersions = $query->orderBy('id', 'asc')->get();
        $articleRecord = $version->article ?? $allVersions->first(static fn($v) => $v->article !== null)?->article;
        $publishedVersionId = $articleRecord?->published_version_id ?? ($version->status === ArticleVersion::STATUS_PUBLISHED ? $version->id : null);

        $vNum = 1;
        $versionsList = [];
        foreach ($allVersions as $relV) {
            $isDraft = (string) $relV->status === ArticleVersion::STATUS_DRAFT;
            $isOwnDraft = $isDraft && $user && (int) $relV->created_by_id === (int) $user->id;
            $creatorName = $relV->createdBy?->name ?? 'Unknown';

            $isCurrent = $publishedVersionId !== null
                && (int) $relV->id === (int) $publishedVersionId
                && (string) $relV->status === ArticleVersion::STATUS_PUBLISHED;

            $versionsList[] = [
                'id'             => $relV->id,
                'version_number' => $vNum++,
                'version_type'   => (string) ($relV->version_type ?? ArticleVersion::TYPE_ORIGINAL),
                'title'          => (string) ($relV->title ?? '-'),
                'status'         => (string) ($relV->status ?? ''),
                'is_current'     => $isCurrent,
                'created_by_id'  => $relV->created_by_id,
                'created_by'     => $creatorName,
                'is_own_draft'   => $isOwnDraft,
                'published_at'   => $relV->published_at?->toDateTimeString(),
                'created_at'     => $relV->created_at?->toDateTimeString(),
                'published_by'   => $relV->publishedBy?->name ?? $relV->createdBy?->name,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => array_reverse($versionsList),
        ]);
    }

    private function baseQuery()
    {
        return ArticleVersion::query()
            ->with([
                'article',
                'authors',
                'bannerImage.gallery',
                'files',
                'assignedTo',
                'createdBy',
                'publishedBy',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at');
    }

    public function publishedArticleDetail(Request $request, $articleId): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Resolves article version details for the Published Article Details drawer.
        $version = ArticleVersion::query()
            ->with([
                'article',
                'authors',
                'bannerImage.gallery',
                'seo',
                'categories',
                'sections.galleryUsages.gallery',
                'publishedBy',
            ])
            ->where('id', $articleId)
            ->firstOrFail();

        $primaryAuthor = $version->authors->first();
        $bannerImage = $version->bannerImage;
        $seo = $version->seo;
        $publishedAt = $version->published_at?->toISOString() ?? $version->article?->last_published_at?->toISOString();

        $userRevisionDraft = ArticleVersion::query()
            ->where('article_id', $version->article_id)
            ->where('parent_version_id', $version->id)
            ->where('version_type', ArticleVersion::TYPE_REVISION)
            ->where('status', ArticleVersion::STATUS_DRAFT)
            ->where('created_by_id', $user->id)
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $version->id,
                'article_id' => $version->article_id,
                'title' => $version->title ?? '-',
                'sub_title' => $version->sub_title,
                'slug' => $version->slug,
                'published_on' => $publishedAt,
                'published_date' => $publishedAt,
                'status' => $version->status,
                'is_published' => $version->status === ArticleVersion::STATUS_PUBLISHED,
                'user_revision_draft_id' => $userRevisionDraft?->id,
                'has_revision_draft' => $userRevisionDraft !== null,
                'author_id' => $primaryAuthor?->id,
                'author' => $primaryAuthor ? [
                    'id' => $primaryAuthor->id,
                    'name' => $primaryAuthor->name,
                ] : null,
                'authors' => $version->authors->map(static fn($author): array => [
                    'id' => $author->id,
                    'name' => $author->name,
                    'slug' => $author->slug,
                ])->values()->all(),
                'has_custom_author' => false,
                'custom_author_name' => null,
                'report_from' => $version->report_from,
                'category_ids' => $version->categories->pluck('id')->values()->all(),
                'categories' => $version->categories->map(static fn($category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])->values()->all(),
                'category_name' => $version->categories->pluck('name')->filter()->join(', '),
                'sections' => $version->sections->map(static fn($section): array => EditorDetailResource::sectionPayload($section))->values()->all(),
                'banner_image' => $bannerImage ? [
                    'id' => $bannerImage->id,
                    'url' => $bannerImage->gallery?->url,
                    'alt_text' => $bannerImage->alt_text,
                    'photo_credit' => $bannerImage->photo_credit,
                    'caption' => $bannerImage->caption,
                ] : null,
                'meta' => $seo ? [
                    'meta_title' => $seo->meta_title,
                    'meta_description' => $seo->meta_descriptions,
                    'meta_keyword' => $seo->focus_keywords,
                ] : null,
                'published_at' => $publishedAt,
                'published_by' => $version->publishedBy?->name,
                'thumb' => $bannerImage?->gallery?->url,
            ],
        ]);
    }
}
