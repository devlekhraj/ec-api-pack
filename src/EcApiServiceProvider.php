<?php

declare(strict_types=1);

namespace EcApi;

use EcApi\Models\Article;
use EcApi\Models\ArticleVersion;
use EcApi\Models\Category;
use EcApi\Models\Short;
use EcApi\Console\Commands\ECimportCommand;
use EcApi\Console\Commands\FixSectionImageUrlsCommand;
use EcApi\Console\Commands\ImportPostFeaturedImagesCommand;
use EcApi\Console\Commands\ImportPostsToArticlesCommand;
use EcApi\Console\Commands\ImportWpFeaturedImagesCommand;
use EcApi\Console\Commands\ListPostImageUrlsCommand;
use EcApi\Console\Commands\PostPrimaryBannerCommand;
use EcApi\Console\Commands\SyncArticleVersionMetaImagesCommand;
use EcApi\Observers\ArticleObserver;
use EcApi\Observers\ArticleVersionObserver;
use EcApi\Observers\CategoryObserver;
use EcApi\Observers\ShortObserver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class EcApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::morphMap([
            'article_versions' => ArticleVersion::class,
            'shorts' => Short::class,
            'live_updates' => \EcApi\Models\LiveUpdate::class,
            'content_sections' => \EcApi\Models\ContentSection::class,
        ]);

        $this->commands([
            ECimportCommand::class,
            FixSectionImageUrlsCommand::class,
            ImportPostFeaturedImagesCommand::class,
            ImportPostsToArticlesCommand::class,
            ImportWpFeaturedImagesCommand::class,
            ListPostImageUrlsCommand::class,
            PostPrimaryBannerCommand::class,
            SyncArticleVersionMetaImagesCommand::class,
        ]);

        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/Routes/article.php');

        Category::observe(CategoryObserver::class);
        Short::observe(ShortObserver::class);
        Article::observe(ArticleObserver::class);
        ArticleVersion::observe(ArticleVersionObserver::class);
    }
}
