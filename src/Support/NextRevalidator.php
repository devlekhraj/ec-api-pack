<?php

declare(strict_types=1);

namespace EcApi\Support;

use EcApi\Models\ArticleVersion;
use EcApi\Models\Category;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class NextRevalidator
{
    /**
     * @param  array<int, string>  $tags
     */
    public static function revalidate(array $tags): void
    {
        $url = config('services.next.revalidate_url');
        $secret = config('services.next.revalidate_secret');

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            return;
        }

        $tags = collect($tags)
            ->map(static fn (string $tag): string => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($tags === []) {
            return;
        }

        try {
            Http::timeout((int) config('services.next.revalidate_timeout', 5))
                ->withHeaders([
                    'X-Revalidate-Secret' => $secret,
                    'Accept' => 'application/json',
                ])
                ->post($url, [
                    'tags' => $tags,
                ])
                ->throw();
        } catch (\Throwable $throwable) {
            Log::warning('Next.js cache revalidation failed.', [
                'url' => $url,
                'tags' => $tags,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function articleTags(ArticleVersion $version): array
    {
        $tags = [
            'homepage',
            'navigation',
            'articles',
            "article:{$version->slug}",
        ];

        foreach ($version->categories as $category) {
            if ($category instanceof Category) {
                $tags[] = 'categories';
                $tags[] = "category:{$category->slug}";
            }
        }

        foreach ($version->authors as $author) {
            if (is_string($author->slug) && $author->slug !== '') {
                $tags[] = "author:{$author->slug}";
            }
        }

        return $tags;
    }

    /**
     * @return array<int, string>
     */
    public static function categoryTags(Category $category): array
    {
        return [
            'homepage',
            'navigation',
            'articles',
            'categories',
            "category:{$category->slug}",
        ];
    }
}
