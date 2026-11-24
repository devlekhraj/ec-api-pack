<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Actions;

use EcApi\Models\Post;
use EcApi\Models\GalleryUsage;

final class PostDeleteAction
{
    public function execute(Post $post): void
    {
        try {
            GalleryUsage::query()
                ->where('usage_type', 'posts')
                ->where('usage_id', $post->id)
                ->delete();
            $post->files()->detach();
        } catch (\Throwable $e) {
            // proceed even if cleanup partially fails
        }

        $post->delete();
    }
}
