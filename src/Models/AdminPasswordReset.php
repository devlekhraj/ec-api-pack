<?php

declare(strict_types=1);

namespace EcApi\Models;

use Illuminate\Database\Eloquent\Model;

final class AdminPasswordReset extends Model
{
    protected $fillable = [
        'email',
        'otp',
        'attempts',
        'reset_token',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected $hidden = ['otp', 'reset_token'];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= 5;
    }
}
