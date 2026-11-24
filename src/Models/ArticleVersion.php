<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\AiRequest;
use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use EcApi\Models\Category;
use EcApi\Models\User;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class ArticleVersion extends BaseModel
{
    public const TYPE_ORIGINAL = 'original';

    public const TYPE_REVISION = 'revision';

    public const TYPE_RESTORE = 'restore';

    public const TYPE_CLONE = 'clone';

    public const ALL_TYPES = [
        self::TYPE_ORIGINAL,
        self::TYPE_REVISION,
        self::TYPE_RESTORE,
        self::TYPE_CLONE,
    ];

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
        'article_id',
        'parent_version_id',
        'original_version_id',
        'version_type',
        'status',
        'title',
        'sub_title',
        'slug',
        'report_from',
        'meta',
        'created_by_id',
        'assigned_by_id',
        'assigned_to_id',
        'submitted_at',
        'published_by_id',
        'published_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'submitted_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    public function originalVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_version_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'original_version_id');
    }

    public function childVersions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_version_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(Gallery::class, 'gallery_usages', 'usage_id', 'gallery_id')
            ->wherePivot('usage_type', 'article_versions')
            ->withPivot(['id', 'usage_type', 'usage_id', 'photo_credit', 'caption', 'alt_text', 'is_primary', 'custom_properties'])
            ->orderByDesc('gallery_usages.created_at')
            ->withTimestamps();
    }

    public function bannerImage(): HasOne
    {
        return $this->hasOne(GalleryUsage::class, 'usage_id', 'id')
            ->where('usage_type', 'article_versions')
            ->where('is_primary', 1)
            ->with('gallery');
    }

    public function galleryUsages(): MorphMany
    {
        return $this->morphMany(GalleryUsage::class, 'usage');
    }

    public function defaultImageUsage(): MorphOne
    {
        return $this->morphOne(GalleryUsage::class, 'usage')
            ->where('collection', GalleryUsage::COLLECTION_DEFAULT)
            ->where('is_primary', true);
    }

    public function galleryImageUsages(): MorphMany
    {
        return $this->morphMany(GalleryUsage::class, 'usage')
            ->where('collection', GalleryUsage::COLLECTION_GALLERY)
            ->orderBy('seq_no');
    }

    public function heroImageUsage(): MorphOne
    {
        return $this->morphOne(GalleryUsage::class, 'usage')
            ->where('collection', GalleryUsage::COLLECTION_HERO)
            ->where('is_primary', true);
    }

    public function bannerImageUsage(): MorphOne
    {
        return $this->morphOne(GalleryUsage::class, 'usage')
            ->where('collection', GalleryUsage::COLLECTION_BANNER)
            ->where('is_primary', true);
    }

    public function socialImageUsage(): MorphOne
    {
        return $this->morphOne(GalleryUsage::class, 'usage')
            ->where('collection', GalleryUsage::COLLECTION_SOCIAL)
            ->where('is_primary', true);
    }

    public function getSocialImage(): ?Gallery
    {
        return $this->socialImageUsage?->gallery
            ?? $this->defaultImageUsage?->gallery;
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'article_version_authors', 'article_version_id', 'user_id')
            ->withPivot(['display_order'])
            ->withTimestamps()
            ->orderBy('article_version_authors.display_order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'article_version_categories', 'article_version_id', 'category_id')
            ->withTimestamps();
    }

    public function seo(): HasOne
    {
        return $this->hasOne(ArticleVersionSeo::class, 'article_version_id');
    }

    public function aiRequests(): MorphMany
    {
        return $this->morphMany(AiRequest::class, 'requestable');
    }

    public function sections(): MorphMany
    {
        return $this->morphMany(ContentSection::class, 'sectionable')->orderBy('sort_order');
    }

    public function liveUpdates(): HasMany
    {
        return $this->hasMany(LiveUpdate::class, 'article_version_id')->latest();
    }
}
