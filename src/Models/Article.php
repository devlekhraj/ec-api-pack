<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\Gallery;
use EcApi\Models\User;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Article extends BaseModel
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEW_REQUEST = 'review_request';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_PUBLISHED = 'published';

    public static string $statusDraft = self::STATUS_DRAFT;

    public static string $statusReviewRequest = self::STATUS_REVIEW_REQUEST;

    public static string $statusInReview = self::STATUS_IN_REVIEW;

    public static string $statusPublished = self::STATUS_PUBLISHED;

    public const ALL_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_REVIEW_REQUEST,
        self::STATUS_IN_REVIEW,
        self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'status',
        'latest_version_id',
        'published_version_id',
        'created_by_id',
        'first_published_at',
        'last_published_at',
    ];

    protected $casts = [
        'first_published_at' => 'datetime',
        'last_published_at' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class, 'article_id');
    }
    
    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'latest_version_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'published_version_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(Gallery::class, 'gallery_usages', 'usage_id', 'gallery_id')
            ->wherePivot('usage_type', 'articles')
            ->withPivot(['id', 'usage_type', 'usage_id', 'photo_credit', 'caption', 'alt_text', 'is_primary', 'custom_properties'])
            ->orderByDesc('gallery_usages.created_at')
            ->withTimestamps();
    }
}
