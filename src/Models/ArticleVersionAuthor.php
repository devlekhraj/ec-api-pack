<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\User;
use EcApi\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ArticleVersionAuthor extends BaseModel
{
    protected $table = 'article_version_authors';

    protected $fillable = [
        'article_version_id',
        'user_id',
        'display_order',
    ];

    public function articleVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
