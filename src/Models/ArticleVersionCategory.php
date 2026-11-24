<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\Category;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ArticleVersionCategory extends BaseModel
{
    protected $table = 'article_version_categories';

    protected $fillable = [
        'article_version_id',
        'category_id',
    ];

    public function articleVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
