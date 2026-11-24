<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiRequest extends Model
{
    protected $fillable = [
        'requestable_type',
        'requestable_id',
        'requested_by',
        'provider',
        'model',
        'feature',
        'action',
        'prompt_version',
        'input_snapshot',
        'output',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost_usd',
        'accepted',
        'accepted_at',
        'accepted_by',
        'meta',
    ];

    protected $casts = [
        'input_snapshot' => 'array',
        'output' => 'array',
        'meta' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost_usd' => 'decimal:6',
        'accepted' => 'boolean',
        'accepted_at' => 'datetime',
    ];

    public function requestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
