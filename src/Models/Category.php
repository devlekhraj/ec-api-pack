<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\Post;
use EcApi\Traits\HasSections;
use EcApi\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory, HasAuditFields, HasSections;

    private const ACTIVE_CATEGORIES_CACHE_KEY = 'active_categories';

    protected $table = 'categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'seq_no',
        'parent_id',
        'is_active',
        'is_deleted',
        'deleted_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['url'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = static::generateUniqueSlug($category->name);
            }
        });
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'category_post', 'category_id', 'post_id');
    }

    public function articleVersions(): BelongsToMany
    {
        return $this->belongsToMany(ArticleVersion::class, 'article_version_categories', 'category_id', 'article_version_id');
    }

    public function getUrlAttribute()
    {
        if (! Route::has('category.show')) {
            return null;
        }

        return route('category.show', ['slug' => $this->slug]);
    }

    protected static function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;

        $count = 1;
        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $count;
            $count++;
        }

        return $slug;
    }
}
