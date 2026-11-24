<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\AiRequest;
use EcApi\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ArticleVersionSeo extends Model
{
    protected $fillable = [
        'article_version_id',
        'canonical_url',

        'meta_title',
        'meta_descriptions',
        
        'og_title',
        'og_description',
        'og_image_url',
        
        'twitter_title',
        'twitter_description',
        'twitter_image_url',
        
        'focus_keywords',
        'json_ld',
        
        'seo_extra',
        
        'ai_optimized',
        'ai_request_count',
        
        'finalized',
        'finalized_at',
        'finalized_by',
        
        'locked',
        'locked_at',
        'locked_by',
    ];

    protected $casts = [
        'seo_extra' => 'array',
        'json_ld' => 'array',
        'ai_optimized' => 'boolean',
        'ai_request_count' => 'integer',
        'finalized' => 'boolean',
        'finalized_at' => 'datetime',
        'locked' => 'boolean',
        'locked_at' => 'datetime',
    ];

    public function articleVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'article_version_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function aiRequests(): MorphMany
    {
        return $this->morphMany(AiRequest::class, 'requestable');
    }
}
