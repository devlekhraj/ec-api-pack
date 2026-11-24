<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Actions;

use EcApi\Models\Post;

final class PostDetailAction
{
    public function execute(string $id): Post
    {
        return Post::query()
            ->with(['bannerImage', 'files', 'categories'])
            ->findOrFail($id);
    }
}
