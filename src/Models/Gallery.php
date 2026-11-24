<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

final class Gallery extends BaseModel
{
    protected $table = 'galleries';

    public const SIZE_DEFAULT = 'default';
    public const SIZE_SM      = 'sm';
    public const SIZE_MD      = 'md';
    public const SIZE_LG      = 'lg';
    public const SIZE_XL      = 'xl';

    public const WIDTH_SM = 480;
    public const WIDTH_MD = 768;
    public const WIDTH_LG = 1200;
    public const WIDTH_XL = 1600;

    /**
     * Note: `alt_text` and `title` columns exist in the DB but are deprecated.
     * All image presentation metadata lives in `gallery_usages`. These columns
     * will be dropped in a future migration after confirming no legacy data
     * depends on them. Do NOT add them back here.
     */
    protected $fillable = [
        'created_by_id',
        'filename',
        'filepath',
        'storage_disk',
        'mime_type',
        'file_size',
        'height',
        'width',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'height'    => 'integer',
        'width'     => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(GalleryUsage::class, 'gallery_id');
    }

    /**
     * Return the relative filepath for the given size.
     *
     * For SIZE_DEFAULT, returns the stored filepath as-is.
     * For sm/md/lg/xl, derives the variant path from the stored filepath.
     */
    public function getPath(string $size = self::SIZE_DEFAULT): string
    {
        if ($size === self::SIZE_DEFAULT) {
            return (string) $this->filepath;
        }

        return $this->buildVariantPath($size);
    }

    /**
     * Return the public URL for the given size.
     *
     * Images are served directly by Nginx/CDN — no Laravel controller route needed.
     * Local: http://localhost/upload/YYYY/MM/DD/filename-sm.jpg
     * Production: https://cdn.everestchronicle.com/YYYY/MM/DD/filename-sm.jpg
     */
    public function getUrl(string $size = self::SIZE_DEFAULT): string
    {
        $disk = (string) ($this->storage_disk ?: config('filesystems.image_fallback_disk', 'public'));

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        return $storage->url($this->getPath($size));
    }

    public function getUrlAttribute(): string
    {
        return $this->getUrl();
    }

    /**
     * Return URLs for all sizes that exist for this image.
     *
     * For social images, only 'default' is meaningful. Use hasSize() to guard
     * variant usage in Blade srcset attributes.
     *
     * @return array<string, string>
     */
    public function getUrls(): array
    {
        $urls = ['default' => $this->getUrl()];

        foreach ([self::SIZE_SM, self::SIZE_MD, self::SIZE_LG, self::SIZE_XL] as $size) {
            if ($this->hasSize($size)) {
                $urls[$size] = $this->getUrl($size);
            }
        }

        return $urls;
    }

    /**
     * Whether this gallery image was wide enough for the given variant to exist.
     *
     * Based on no-upscale rule: a variant is only generated when source width >= variant width.
     */
    public function hasSize(string $size): bool
    {
        return match ($size) {
            self::SIZE_SM      => (int) $this->width >= self::WIDTH_SM,
            self::SIZE_MD      => (int) $this->width >= self::WIDTH_MD,
            self::SIZE_LG      => (int) $this->width >= self::WIDTH_LG,
            self::SIZE_XL      => (int) $this->width >= self::WIDTH_XL,
            self::SIZE_DEFAULT => true,
            default            => false,
        };
    }

    /**
     * Derive the variant filepath from the stored default filepath.
     *
     * Example:
     *   filepath: 2026/09/20/everest-base-camp.jpg
     *   size: sm
     *   result:  2026/09/20/everest-base-camp-sm.jpg
     */
    protected function buildVariantPath(string $size): string
    {
        $allowedSizes = [self::SIZE_SM, self::SIZE_MD, self::SIZE_LG, self::SIZE_XL];

        if (! in_array($size, $allowedSizes, true)) {
            throw new \InvalidArgumentException("Invalid gallery image size [{$size}].");
        }

        $filepath  = (string) $this->filepath;
        $directory = dirname($filepath);
        $basename  = pathinfo($filepath, PATHINFO_FILENAME);
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);

        return "{$directory}/{$basename}-{$size}.{$extension}";
    }
}
