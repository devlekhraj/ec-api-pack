<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\User;
use EcApi\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Lookup extends Model
{
    use HasFactory, HasAuditFields;

    protected $fillable = [
        'code',
        'value',
        'parent_id',
        'seq_no',
        'file_name',
        'description',
        'is_active',
        'is_deleted',
        'deleted_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function children()
    {
        return $this->hasMany(Lookup::class, 'parent_id')
            ->select(['id', 'value as name', 'parent_id']);
    }

    public function parent()
    {
        return $this->belongsTo(Lookup::class, 'parent_id')
            ->select(['id', 'value as name']);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
