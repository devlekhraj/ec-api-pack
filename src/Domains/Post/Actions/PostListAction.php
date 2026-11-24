<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Actions;

use EcApi\Models\Post;

final class PostListAction
{
    public function execute(?string $search, int $perPageParam, mixed $categoryId = null, mixed $authorId = null): array
    {
        $query = Post::query()
            ->with(['bannerImage', 'categories'])
            ->orderByDesc('created_at');

        $search = is_string($search) ? trim($search) : '';
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (filled($categoryId)) {
            $query->whereHas('categories', function ($builder) use ($categoryId): void {
                $builder->where('categories.id', (int) $categoryId);
            });
        }
    
        if (filled($authorId)) {
            $query->where('author_id', (int) $authorId);
        }

        if ($perPageParam === -1) {
            $items = $query->get();

            return [
                'items' => $items,
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $items->count(),
                    'total' => $items->count(),
                    'last_page' => 1,
                    'from' => $items->count() > 0 ? 1 : null,
                    'to' => $items->count() > 0 ? $items->count() : null,
                ],
            ];
        }

        $perPage = max(1, min($perPageParam, 100));
        $paginator = $query->paginate($perPage);

        return [
            'items' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
