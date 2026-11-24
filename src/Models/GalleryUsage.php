<?php

declare(strict_types=1);

namespace EcApi\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class GalleryUsage extends BaseModel
{
    public const COLLECTION_DEFAULT = 'default';
    public const COLLECTION_GALLERY = 'gallery';
    public const COLLECTION_AVATAR  = 'avatar';
    public const COLLECTION_HERO    = 'hero';
    public const COLLECTION_BANNER  = 'banner';
    public const COLLECTION_SOCIAL  = 'social';
    public const COLLECTION_IMAGE   = 'image';
    public const COLLECTION_FULL_WIDTH_IMAGE = 'full_width_image';
    public const COLLECTION_MULTI_IMAGE = 'multi_image';
    public const COLLECTION_THUMBNAIL = 'thumbnail';

    public static string $collectionDefault = self::COLLECTION_DEFAULT;
    public static string $collectionGallery = self::COLLECTION_GALLERY;
    public static string $collectionAvatar  = self::COLLECTION_AVATAR;
    public static string $collectionHero    = self::COLLECTION_HERO;
    public static string $collectionBanner  = self::COLLECTION_BANNER;
    public static string $collectionSocial  = self::COLLECTION_SOCIAL;
    public static string $collectionImage   = self::COLLECTION_IMAGE;
    public static string $collectionFullWidthImage = self::COLLECTION_FULL_WIDTH_IMAGE;
    public static string $collectionMultiImage = self::COLLECTION_MULTI_IMAGE;
    public static string $collectionThumbnail = self::COLLECTION_THUMBNAIL;

    public const COLLECTIONS = [
        self::COLLECTION_DEFAULT,
        self::COLLECTION_GALLERY,
        self::COLLECTION_AVATAR,
        self::COLLECTION_HERO,
        self::COLLECTION_BANNER,
        self::COLLECTION_SOCIAL,
        self::COLLECTION_IMAGE,
        self::COLLECTION_FULL_WIDTH_IMAGE,
        self::COLLECTION_MULTI_IMAGE,
        self::COLLECTION_THUMBNAIL,
    ];

    public const SINGLE_SLOT_COLLECTIONS = [
        self::COLLECTION_AVATAR,
        self::COLLECTION_HERO,
        self::COLLECTION_BANNER,
        self::COLLECTION_SOCIAL,
        self::COLLECTION_IMAGE,
        self::COLLECTION_FULL_WIDTH_IMAGE,
        self::COLLECTION_THUMBNAIL,
    ];

    public static array $collections = self::COLLECTIONS;

    public static array $singleSlotCollections = self::SINGLE_SLOT_COLLECTIONS;

    public static function isSingleSlotCollection(string $collection): bool
    {
        return in_array($collection, self::SINGLE_SLOT_COLLECTIONS, true);
    }

    protected $table = 'gallery_usages';

    protected $fillable = [
        'gallery_id',
        'created_by_id',
        'usage_id',
        'usage_type',
        'collection',
        'seq_no',
        'alt_text',
        'photo_credit',
        'caption',
        'custom_properties',
        'title',
        'description',
        'is_primary',
    ];

    protected $appends = ['url'];

    protected $casts = [
        'custom_properties' => 'array',
        'is_primary'        => 'boolean',
        'seq_no'            => 'integer',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class, 'gallery_id');
    }

    public function usage(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->gallery?->getUrl();
    }
}
