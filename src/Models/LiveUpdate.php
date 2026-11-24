<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\User;
use EcApi\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Auth;

class LiveUpdate extends BaseModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public static string $statusDraft = self::STATUS_DRAFT;
    public static string $statusPublished = self::STATUS_PUBLISHED;

    protected $table = 'live_updates';

    protected $fillable = [
        'article_version_id',
        'title',
        'sub_title',
        'status',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'article_version_id' => 'integer',
        'created_by' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (LiveUpdate $model): void {
            if (! $model->created_by && Auth::check()) {
                $model->created_by = Auth::id();
            }
            if (! $model->status) {
                $model->status = self::STATUS_PUBLISHED;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function articleVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function banner(): MorphOne
    {
        return $this->morphOne(GalleryUsage::class, 'usage')
            ->where('collection', GalleryUsage::COLLECTION_BANNER)
            ->where('is_primary', true)
            ->with('gallery');
    }

    public function bannerImage(): HasOne
    {
        return $this->hasOne(GalleryUsage::class, 'usage_id', 'id')
            ->where('usage_type', 'live_updates')
            ->where('is_primary', 1)
            ->with('gallery');
    }

    public function galleryUsages(): MorphMany
    {
        return $this->morphMany(GalleryUsage::class, 'usage');
    }

    public function sections(): MorphMany
    {
        return $this->morphMany(ContentSection::class, 'sectionable')->orderBy('sort_order');
    }
}
