<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\Category;
use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use EcApi\Models\User;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Post extends BaseModel
{
    protected $table = 'posts';

    protected $fillable = [
        'title',
        'sub_title',
        'slug',
        'author_id',
        'report_from',
        'status',
        'content',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'category_id',
        'is_published',
        'is_home_featured',
        'is_featured',
        'featured_image',
        'published_on',
        'photo_description',
        'photo_credit',
        'custom_author_name',
        'has_custom_author',
        'is_deleted',
    ];
    protected $appends = [
        'banner_url',
    ];

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_post', 'post_id', 'category_id');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(Gallery::class, 'gallery_usages', 'usage_id', 'gallery_id')
            ->wherePivot('usage_type', 'posts')
            ->withPivot(['id', 'usage_type', 'usage_id', 'photo_credit', 'caption', 'alt_text', 'is_primary', 'custom_properties'])
            ->orderByDesc('gallery_usages.created_at')
            ->withTimestamps();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }


    public function bannerImage(): HasOne
    {
        return $this->hasOne(GalleryUsage::class, 'usage_id', 'id')
            ->where('usage_type', 'posts')
            ->where('is_primary', 1)
            ->with('gallery');
    }

    public function getBannerUrlAttribute()
    {
        if ($this->feature_image) {
            return $this->feature_image;
        } elseif ($this->cover_image) {
            return route('image.view', ['filename' => $this->cover_image]);
        } else {
            return null;
        }
    }
}
