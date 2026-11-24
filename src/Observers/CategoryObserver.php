<?php

declare(strict_types=1);

namespace EcApi\Observers;

use EcApi\Models\Category;
use EcApi\Support\NextRevalidator;
use Illuminate\Support\Facades\Cache;

final class CategoryObserver
{
    public function saved(Category $category): void
    {
        Cache::forget('active_categories');
        NextRevalidator::revalidate(NextRevalidator::categoryTags($category));
    }

    public function deleted(Category $category): void
    {
        Cache::forget('active_categories');
        NextRevalidator::revalidate(NextRevalidator::categoryTags($category));
    }

    public function restored(Category $category): void
    {
        Cache::forget('active_categories');
        NextRevalidator::revalidate(NextRevalidator::categoryTags($category));
    }
}
