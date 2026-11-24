<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LandingPageSetting extends BaseModel
{
    public const HERO_MODE_AUTO = 'auto';

    public const HERO_MODE_MANUAL = 'manual';

    protected $table = 'landing_page_settings';

    protected $fillable = [
        'hero_mode',
        'hero_article_id',
        'hero_custom_title',
        'hero_custom_excerpt',
        'featured_card_active',
        'featured_card_article_id',
        'featured_card_custom_title',
        'featured_card_badge',
        'meta',
    ];

    protected $casts = [
        'featured_card_active' => 'boolean',
        'meta' => 'array',
    ];

    public function heroArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'hero_article_id');
    }

    public function featuredCardArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'featured_card_article_id');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'hero_mode' => self::HERO_MODE_AUTO,
            'hero_article_id' => null,
            'featured_card_active' => false,
            'featured_card_article_id' => null,
        ]);
    }
}
