<?php

declare(strict_types=1);

namespace EcApi\Domains\Shared\Controllers;

use EcApi\Models\Gallery;
use EcApi\Models\Post;
use EcApi\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class OverviewController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'totalArticles' => Post::query()->count(),
                'totalAuthors' => User::query()->count(),
                'totalMedia' => Gallery::query()->count(),
                'totalVisitors' => 120,
            ],
        ]);
    }

    public function recentArticles(): JsonResponse
    {
        $articles = Post::query()
            ->with('categories')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(static function (Post $post): array {
                $categoryName = $post->categories->first()?->name;

                return [
                    'id' => $post->id,
                    'title' => (string) ($post->title ?? '-'),
                    'description' => (string) ($post->short_desc ?: $categoryName ?: 'Recent article'),
                    'time' => optional($post->created_at)->diffForHumans() ?? '',
                    'icon' => 'mdi-post-outline',
                    'entity_type' => 'posts',
                    'entity_id' => $post->id,
                ];
            })
            ->values();

        return response()->json([
            'data' => $articles,
        ]);
    }
}
