<?php

declare(strict_types=1);

namespace EcApi\Models;

use EcApi\Models\Post;
use EcApi\Traits\HasSections;
use EcApi\Traits\HasAuditFields;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasAuditFields, HasSections;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_AUTHOR = 'author';
    public const ROLE_DEFAULT = self::ROLE_AUTHOR;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'profile_pic',
        'bio',
        'password',
        'role',
        'role_name',
        'username',
    ];

    protected $appends = ['url', 'roles_array'];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verified_at',
        'updated_at',
        'created_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public static function validRoles(): array
    {
        return [
            self::ROLE_ADMIN,
            self::ROLE_EDITOR,
            self::ROLE_AUTHOR,
        ];
    }

    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user): void {
            if (empty($user->username)) {
                $username = str_replace(' ', '', strtolower($user->name));

                while (self::where('username', $username)->exists()) {
                    $username = str_replace(' ', '', strtolower($user->name)) . rand(1000, 9999);
                }

                $user->username = $username;
            }

            if (empty($user->slug)) {
                $user->slug = Str::slug($user->name);
            }
        });

        static::updating(function ($user): void {
            if (empty($user->username)) {
                $username = str_replace(' ', '', strtolower($user->name));

                while (self::where('username', $username)->exists()) {
                    $username = str_replace(' ', '', strtolower($user->name)) . rand(1000, 9999);
                }

                $user->username = $username;
            }

            if (empty($user->slug)) {
                $user->slug = Str::slug($user->name);
            }
        });
    }

    public function rolesArray(): array
    {
        return $this->role_name ? [$this->role_name] : [];
    }

    public function getRolesArrayAttribute(): array
    {
        return $this->rolesArray();
    }

    public function getRoleAttribute(): ?string
    {
        return $this->role_name;
    }

    public function getUrlAttribute()
    {
        if (! Route::has('author.show')) {
            return null;
        }

        return route('author.show', ['slug' => $this->slug]);
    }

    public function posts()
    {
        return $this->hasMany(Post::class, 'author_id', 'id');
    }

    public function articleVersions(): BelongsToMany
    {
        return $this->belongsToMany(ArticleVersion::class, 'article_version_authors', 'user_id', 'article_version_id');
    }

    public function hasAnyRoles($roles)
    {
        if (is_string($roles)) {
            $roles = [$roles];
        }

        return in_array($this->role_name, $roles, true);
    }

    public function hasRole($role)
    {
        return $this->role_name === $role;
    }
}
