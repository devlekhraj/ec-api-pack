<?php

declare(strict_types=1);

namespace EcApi\Observers;

use EcApi\Models\Article;
use EcApi\Support\NextRevalidator;

final class ArticleObserver
{
    public function deleted(Article $article): void
    {
        $publishedVersion = $article->publishedVersion ?? ($article->published_version_id ? $article->publishedVersion()->first() : null);

        if ($publishedVersion) {
            $publishedVersion->loadMissing(['authors', 'categories']);
            NextRevalidator::revalidate(NextRevalidator::articleTags($publishedVersion));
        }
    }
}
