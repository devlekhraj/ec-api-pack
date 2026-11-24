<?php

declare(strict_types=1);

namespace EcApi\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Comment extends Model
{
    use HasFactory;

    protected $table = 'comments';

    protected $fillable = [
        'post_id',
        'author_name',
        'author_email',
        'author_url',
        'author_ip',
        'comment_date',
        'content',
        'approved',
        'comment_type',
        'comment_parent',
        'user_id',
    ];

    protected $dates = ['comment_date'];
}
