<?php

declare(strict_types=1);

namespace EcApi\Models;

use App\Support\Eloquent\BaseModel;
use EcApi\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class Short extends BaseModel
{
    use HasFactory, HasAuditFields, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $table = 'shorts';

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'description',
        'location',
        'video_url',
        'redirect_url',
        'status',
        'sort_order',
        'published_at',
        'meta',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'published_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $appends = [
        'image_url',
        'has_video',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mediaUsage(): MorphOne
    {
        return $this->morphOne(GalleryUsage::class, 'usage')
            ->where(function (Builder $query) {
                $query->where('collection', 'thumbnail')
                    ->orWhere('collection', GalleryUsage::COLLECTION_DEFAULT);
            })
            ->where('is_primary', true);
    }

    public function galleryUsages(): MorphMany
    {
        return $this->morphMany(GalleryUsage::class, 'usage');
    }

    public function getImageUrlAttribute(): ?string
    {
        $galleryUrl = $this->mediaUsage?->gallery?->getUrl();
        if ($galleryUrl) {
            return $galleryUrl;
        }

        if (! empty($this->video_url)) {
            return self::extractVideoThumbnail($this->video_url);
        }

        return null;
    }

    public static function extractVideoThumbnail(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $trimmed = trim($url);

        // YouTube: watch?v=, youtu.be/, embed/, shorts/
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $trimmed, $matches)) {
            return "https://img.youtube.com/vi/{$matches[1]}/hqdefault.jpg";
        }

        // Vimeo
        if (preg_match('/(?:vimeo\.com\/)(\d+)/i', $trimmed, $matches)) {
            return "https://vumbnail.com/{$matches[1]}.jpg";
        }

        return null;
    }

    public function getHasVideoAttribute(): bool
    {
        return ! empty($this->video_url);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public static function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug ?: 'short-' . Str::random(6);
        $count = 1;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = "{$baseSlug}-{$count}";
            $count++;
        }

        return $slug;
    }
}
