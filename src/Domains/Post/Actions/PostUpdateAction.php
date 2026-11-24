<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Actions;

use EcApi\Domains\Post\DTOs\PostUpdateData;
use EcApi\Models\Post;

final class PostUpdateAction
{
    public function execute(Post $post, PostUpdateData $data): Post
    {
        $categoryIds = array_values(array_filter(
            $data->category_ids,
            static fn ($categoryId) => $categoryId !== null && $categoryId !== '',
        ));

        $post->fill([
            'title' => $data->title,
            'slug' => $data->slug,
            'sub_title' => $data->sub_title,
            'content' => $data->content,
            'report_from' => $data->report_from,
            'author_id' => $data->has_custom_author ? null : $data->author_id,
            'has_custom_author' => $data->has_custom_author,
            'custom_author_name' => $data->has_custom_author
                ? ($data->custom_author_name !== null ? trim($data->custom_author_name) : null)
                : null,
            'status' => $data->status??'draft',
            'meta_title' => $data->meta_title,
            'meta_keyword' => $data->meta_keyword,
            'meta_description' => $data->meta_description,
            // 'status' => 'draft',
        ]);
        $post->save();
        $post->categories()->sync($categoryIds);

        return $post->refresh()->load('categories', 'author', 'bannerImage');
    }
}
