<?php

declare(strict_types=1);

namespace EcApi\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentSection extends BaseModel
{
    public const BLOCK_TYPE_PARAGRAPH = 'paragraph';
    public const BLOCK_TYPE_HEADING = 'heading';
    public const BLOCK_TYPE_IMAGE = 'image';
    public const BLOCK_TYPE_VIDEO = 'video';
    public const BLOCK_TYPE_QUOTE = 'quote';
    public const BLOCK_TYPE_CALLOUT = 'callout';
    public const BLOCK_TYPE_LIST = 'list';
    public const BLOCK_TYPE_DIVIDER = 'divider';
    public const BLOCK_TYPE_TABLE = 'table';
    public const BLOCK_TYPE_CODE = 'code';
    public const BLOCK_TYPE_EMBED = 'embed';
    public const BLOCK_TYPE_AD = 'ad';

    public static string $blockTypeParagraph = self::BLOCK_TYPE_PARAGRAPH;
    public static string $blockTypeHeading = self::BLOCK_TYPE_HEADING;
    public static string $blockTypeImage = self::BLOCK_TYPE_IMAGE;
    public static string $blockTypeVideo = self::BLOCK_TYPE_VIDEO;
    public static string $blockTypeQuote = self::BLOCK_TYPE_QUOTE;
    public static string $blockTypeCallout = self::BLOCK_TYPE_CALLOUT;
    public static string $blockTypeList = self::BLOCK_TYPE_LIST;
    public static string $blockTypeDivider = self::BLOCK_TYPE_DIVIDER;
    public static string $blockTypeTable = self::BLOCK_TYPE_TABLE;
    public static string $blockTypeCode = self::BLOCK_TYPE_CODE;
    public static string $blockTypeEmbed = self::BLOCK_TYPE_EMBED;
    public static string $blockTypeAd = self::BLOCK_TYPE_AD;

    public const ALL_BLOCK_TYPES = [
        self::BLOCK_TYPE_PARAGRAPH,
        self::BLOCK_TYPE_HEADING,
        self::BLOCK_TYPE_IMAGE,
        self::BLOCK_TYPE_VIDEO,
        self::BLOCK_TYPE_QUOTE,
        self::BLOCK_TYPE_CALLOUT,
        self::BLOCK_TYPE_LIST,
        self::BLOCK_TYPE_DIVIDER,
        self::BLOCK_TYPE_TABLE,
        self::BLOCK_TYPE_CODE,
        self::BLOCK_TYPE_EMBED,
        self::BLOCK_TYPE_AD,
    ];

    public static array $allBlockTypes = self::ALL_BLOCK_TYPES;

    protected $table = 'content_sections';

    protected $fillable = [
        'sectionable_type',
        'sectionable_id',
        'block_type',
        'sort_order',
        'text',
        'payload',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'payload' => 'array',
    ];

    public static function blockTypes(): array
    {
        return self::ALL_BLOCK_TYPES;
    }

    public static function labels(): array
    {
        return [
            self::BLOCK_TYPE_PARAGRAPH => 'Paragraph',
            self::BLOCK_TYPE_HEADING => 'Heading',
            self::BLOCK_TYPE_IMAGE => 'Image / Gallery',
            self::BLOCK_TYPE_VIDEO => 'Video',
            self::BLOCK_TYPE_QUOTE => 'Quote',
            self::BLOCK_TYPE_CALLOUT => 'Callout',
            self::BLOCK_TYPE_LIST => 'List',
            self::BLOCK_TYPE_DIVIDER => 'Divider',
            self::BLOCK_TYPE_TABLE => 'Table',
            self::BLOCK_TYPE_CODE => 'Code / Preformatted',
            self::BLOCK_TYPE_EMBED => 'Interactive / Embed',
            self::BLOCK_TYPE_AD => 'Advertisement',
        ];
    }

    public static function label(string $blockType): string
    {
        return self::labels()[$blockType] ?? $blockType;
    }

    public function sectionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function galleryUsages(): MorphMany
    {
        return $this->morphMany(GalleryUsage::class, 'usage')
            ->orderBy('seq_no')
            ->orderBy('id');
    }

    public function getTypeAttribute(): ?string
    {
        return $this->block_type;
    }

    public function setTypeAttribute(?string $value): void
    {
        $this->attributes['block_type'] = $value;
    }
}
