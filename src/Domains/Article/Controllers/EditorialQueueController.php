<?php

declare(strict_types=1);

namespace EcApi\Domains\Article\Controllers;

use EcApi\Models\Article;
use EcApi\Models\ArticleVersion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EditorialQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPageParam = (int) $request->query('per_page', 15);

        $query = Article::query()
            ->with([
                'latestVersion' => function ($builder): void {
                    $builder->where('status', ArticleVersion::STATUS_REVIEW_REQUEST)
                        ->with(['authors', 'bannerImage.gallery', 'files']);
                },
            ])
            ->whereHas('latestVersion', function ($builder): void {
                $builder->where('status', ArticleVersion::STATUS_REVIEW_REQUEST);
            })
            ->orderByDesc(
                ArticleVersion::query()
                    ->select('submitted_at')
                    ->whereColumn('article_versions.id', 'articles.latest_version_id')
            )
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $query->whereHas('latestVersion', function ($builder) use ($search): void {
                $builder->where(function ($subQuery) use ($search): void {
                    $subQuery->where('title', 'like', '%' . $search . '%')
                        ->orWhere('slug', 'like', '%' . $search . '%');
                });
            });
        }

        if ($perPageParam === -1) {
            $items = $query->get();

            return response()->json([
                'data' => $this->mapEditorialQueueItems($items),
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
            'data' => $this->mapEditorialQueueItems($paginator->getCollection()),
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

    private function mapEditorialQueueItems($articles): array
    {
        return collect($articles)->map(static function (Article $article): array {
            $latestVersion = $article->latestVersion;
            $authors = $latestVersion?->authors?->pluck('name')->filter()->values() ?? collect();

            return [
                'id' => $article->id,
                'title' => (string) ($latestVersion?->title ?? '-'),
                'slug' => (string) ($latestVersion?->slug ?? ''),
                'status' => $latestVersion?->status,
                'authors' => $authors->isNotEmpty() ? $authors->implode(', ') : '-',
                'submitted_at' => $latestVersion?->submitted_at,
                'updated_at' => $article->updated_at,
                'file_counts' => $latestVersion?->files?->count() ?? 0,
                'thumb' => $latestVersion?->bannerImage?->gallery?->url,
            ];
        })->values()->all();
    }
}
