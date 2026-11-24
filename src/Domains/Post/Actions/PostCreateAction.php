<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Actions;

use EcApi\Models\Category;
use EcApi\Domains\Post\DTOs\PostCreateData;
use EcApi\Models\Post;

final class PostCreateAction
{
    public function execute(PostCreateData $data): Post
    {
        $category = Category::query()
            ->where('slug', 'unlisted')
            ->first();

        if ($category && ($category->is_deleted ?? false)) {
            $category->is_deleted = false;
            $category->is_active = true;
            $category->save();
        }

        if (! $category) {
            $category = Category::query()->create([
                'name' => 'Unlisted',
                'slug' => 'unlisted',
                'description' => '',
                'seq_no' => 0,
                'parent_id' => null,
                'is_active' => true,
            ]);
        }

        return Post::query()->create([
            'title' => $data->title,
            'slug' => $data->slug,
            'status' => $data->status,
            'category_id' => $category->id,
            'content' => '',
            'author' => '',
        ]);
    }
}
