<?php

declare(strict_types=1);

namespace EcApi\Traits;

use Illuminate\Support\Facades\Auth;

trait HasAuditFields
{
    public static function bootHasAuditFields(): void
    {
        static::creating(function ($model): void {
            if (Auth::check() && empty($model->created_by)) {
                $model->created_by = Auth::id();
            }

            if (Auth::check() && empty($model->updated_by)) {
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model): void {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function ($model): void {
            if (Auth::check() && in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive($model), true)) {
                $model->deleted_by = Auth::id();
                $model->saveQuietly();
            }
        });
    }
}
