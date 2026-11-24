<?php

declare(strict_types=1);

namespace EcApi\Domains\LandingPage\Controllers;

use EcApi\Models\Article;
use EcApi\Models\ArticleVersion;
use EcApi\Models\LandingPageSetting;
use EcApi\Support\NextRevalidator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LandingPageController extends Controller
{
    public function getSettings(): JsonResponse
    {
        $setting = LandingPageSetting::current();

        $heroArticle = null;
        if ($setting->hero_article_id) {
            $heroArticle = $this->formatArticleCard(Article::query()->find($setting->hero_article_id));
        }

        $featuredCardArticle = null;
        if ($setting->featured_card_article_id) {
            $featuredCardArticle = $this->formatArticleCard(Article::query()->find($setting->featured_card_article_id));
        }

        return response()->json([
            'data' => [
                'id' => $setting->id,
                'hero_mode' => $setting->hero_mode,
                'hero_article_id' => $setting->hero_article_id,
                'hero_article' => $heroArticle,
                'hero_custom_title' => $setting->hero_custom_title,
                'hero_custom_excerpt' => $setting->hero_custom_excerpt,
                'featured_card_active' => (bool) $setting->featured_card_active,
                'featured_card_article_id' => $setting->featured_card_article_id,
                'featured_card_article' => $featuredCardArticle,
                'featured_card_custom_title' => $setting->featured_card_custom_title,
                'featured_card_badge' => $setting->featured_card_badge,
                'meta' => $setting->meta,
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hero_mode' => 'required|in:auto,manual',
            'hero_article_id' => 'nullable|integer|exists:articles,id',
            'hero_custom_title' => 'nullable|string|max:255',
            'hero_custom_excerpt' => 'nullable|string|max:1000',
            'featured_card_active' => 'required|boolean',
            'featured_card_article_id' => 'nullable|integer|exists:articles,id',
            'featured_card_custom_title' => 'nullable|string|max:255',
            'featured_card_badge' => 'nullable|string|max:100',
            'meta' => 'nullable|array',
        ]);

        $setting = LandingPageSetting::current();
        $setting->update($validated);

        // Revalidate Next.js homepage cache so updates are live immediately
        NextRevalidator::revalidate(['homepage', 'articles']);

        return $this->getSettings();
    }

    public function searchArticles(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('query', ''));

        $query = Article::query()
            ->where('status', Article::STATUS_PUBLISHED)
            ->whereNotNull('published_version_id')
            ->whereHas('publishedVersion', static function ($builder): void {
                $builder->where('status', ArticleVersion::STATUS_PUBLISHED);
            })
            ->with([
                'publishedVersion.authors',
                'publishedVersion.categories',
                'publishedVersion.bannerImage.gallery',
            ]);

        if ($search !== '') {
            $query->whereHas('publishedVersion', static function ($builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $articles = $query->orderByDesc('last_published_at')
            ->limit(20)
            ->get()
            ->map(fn (Article $article): array => $this->formatArticleCard($article))
            ->filter()
            ->values();

        return response()->json([
            'data' => $articles,
        ]);
    }

    private function formatArticleCard(?Article $article): ?array
    {
        if (! $article) {
            return null;
        }

        $version = $article->publishedVersion;
        if (! $version) {
            return null;
        }

        $primaryAuthor = $version->authors->first();
        $authorName = $primaryAuthor?->name ?? $version->publishedBy?->name ?? 'Everest Chronicle';

        return [
            'id' => $article->id,
            'version_id' => $version->id,
            'title' => (string) ($version->title ?? '-'),
            'slug' => (string) ($version->slug ?? ''),
            'excerpt' => (string) ($version->sub_title ?? ''),
            'author_name' => $authorName,
            'published_at' => ($version->published_at ?? $article->last_published_at)?->toFormattedDateString(),
            'thumb' => $version->bannerImage?->gallery?->url,
            'categories' => $version->categories->pluck('name')->values()->all(),
        ];
    }
}
