<?php

declare(strict_types=1);

namespace EcApi\Domains\Menu\Controllers;

use EcApi\Models\Article;
use EcApi\Models\ArticleVersion;
use EcApi\Models\Category;
use EcApi\Models\Comment;
use EcApi\Models\Gallery;
use EcApi\Models\Short;
use EcApi\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminMenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $roles = $this->resolveRoles($user);

            $menu = $this->isAuthorOnly($roles)
                ? $this->buildAuthorMenu($user)
                : $this->buildEditorMenu($user);

            return response()->json([
                'data' => $menu,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    private function buildAuthorMenu(User $user): array
    {
        $userId = $user->id;

        return [
            [
                'group' => 'Dashboard',
                'icon' => 'mdi-chart-bell-curve-cumulative',
                'routeName' => 'admin.overview',
                'count' => null,
            ],
            [
                'group' => 'My Articles',
                'icon' => 'mdi-post-outline',
                'routeName' => 'my.articles.page',
                'count' => null,
            ],
            [
                'group' => 'Shorts',
                'icon' => 'mdi-play-box-multiple-outline',
                'routeName' => 'admin.shorts.list',
                'count' => $this->countShorts($userId),
            ],
            [
                'group' => 'Resources',
                'icon' => 'mdi-image-outline',
                'routeName' => 'admin.files.list',
                'count' => $this->countResources($userId, false),
            ],
            [
                'group' => 'Comments',
                'icon' => 'mdi-comment-text-outline',
                'disabled' => true,
                'count' => $this->countPendingComments(),
            ],
            [
                'group' => 'Notifications',
                'icon' => 'mdi-bell-outline',
                'disabled' => true,
                'count' => 0,
            ],
            [
                'group' => 'Profile',
                'icon' => 'mdi-account-circle-outline',
                'routeName' => $userId ? 'authors.detail' : null,
                'routeParams' => $userId ? ['id' => $userId] : null,
                'disabled' => ! $userId,
            ],
        ];
    }

    private function buildEditorMenu(User $user): array
    {
        $userId = $user->id;

        return [
            [
                'group' => 'Dashboard',
                'icon' => 'mdi-chart-bell-curve-cumulative',
                'routeName' => 'admin.overview',
                'count' => null,
            ],
            [
                'group' => 'Published Articles',
                'icon' => 'mdi-playlist-check',
                'routeName' => 'published.articles.page',
                'count' => $this->countPublishedArticles(),
            ],
            [
                'group' => 'Editorial Queue',
                'icon' => 'mdi-clipboard-text-clock-outline',
                'routeName' => 'editorial.queue.page',
                'count' => $this->countEditorialQueueArticles(),
            ],
            [
                'group' => 'My Articles',
                'icon' => 'mdi-post-outline',
                'routeName' => 'my.articles.page',
                'count' => null,
            ],
            [
                'group' => 'Categories',
                'icon' => 'mdi-shape-outline',
                'routeName' => 'admin.categories.list',
                'count' => $this->countCategories(),
            ],
            [
                'group' => 'Authors',
                'icon' => 'mdi-account-group-outline',
                'routeName' => 'authors.page',
                'count' => $this->countAuthors(),
            ],
            [
                'group' => 'Shorts',
                'icon' => 'mdi-play-box-multiple-outline',
                'routeName' => 'admin.shorts.list',
                'count' => $this->countShorts(),
            ],
            [
                'group' => 'Resources',
                'icon' => 'mdi-image-outline',
                'routeName' => 'admin.files.list',
                'count' => $this->countResources($userId, true),
            ],
            [
                'group' => 'Comments',
                'icon' => 'mdi-comment-text-outline',
                'disabled' => true,
                'count' => $this->countPendingComments(),
            ],
            [
                'group' => 'Notifications',
                'icon' => 'mdi-bell-outline',
                'disabled' => true,
                'count' => 0,
            ],
        ];
    }

    private function resolveRoles(User $user): array
    {
        $roles = [];

        $roleList = $user->roles_array ?? [];
        if (is_array($roleList)) {
            foreach ($roleList as $role) {
                if (! is_string($role)) {
                    continue;
                }

                $normalized = strtolower(trim($role));
                if ($normalized !== '') {
                    $roles[] = $normalized;
                }
            }
        }

        $fallbackRole = $user->role_name;
        if (is_string($fallbackRole)) {
            $normalized = strtolower(trim($fallbackRole));
            if ($normalized !== '') {
                $roles[] = $normalized;
            }
        }

        return array_values(array_unique($roles));
    }

    private function isAuthorOnly(array $roles): bool
    {
        return in_array('author', $roles, true)
            && ! in_array('admin', $roles, true)
            && ! in_array('editor', $roles, true);
    }

    private function countDraftArticles(int|string $userId): int
    {
        return ArticleVersion::query()
            ->where('created_by_id', $userId)
            ->where('status', ArticleVersion::STATUS_DRAFT)
            ->count();
    }

    private function countUnderReviewArticles(int|string $userId): int
    {
        return ArticleVersion::query()
            ->where('created_by_id', $userId)
            ->whereIn('status', [
                ArticleVersion::STATUS_REVIEW_REQUEST,
                ArticleVersion::STATUS_IN_REVIEW,
            ])
            ->count();
    }

    private function countPublishedArticles(): int
    {
        return ArticleVersion::query()
            ->where('status', ArticleVersion::STATUS_PUBLISHED)
            ->where(function ($builder): void {
                $builder->whereDoesntHave('article')
                    ->orWhereHas('article', function ($articleQuery): void {
                        $articleQuery->whereNotNull('published_version_id')
                            ->whereColumn('articles.published_version_id', 'article_versions.id');
                    });
            })
            ->where(function ($builder): void {
                $builder->whereDoesntHave('article', function ($articleQuery): void {
                    $articleQuery->whereNotNull('published_version_id')
                        ->whereColumn('articles.published_version_id', '!=', 'article_versions.id');
                });
            })
            ->count();
    }

    private function countAssignedToMe(int|string $userId): int
    {
        return ArticleVersion::query()
            ->where('assigned_to_id', $userId)
            ->where('status', ArticleVersion::STATUS_IN_REVIEW)
            ->count();
    }

    private function countEditorialQueueArticles(): int
    {
        return Article::query()
            ->whereHas('latestVersion', function ($builder): void {
                $builder->where('status', ArticleVersion::STATUS_REVIEW_REQUEST);
            })
            ->count();
    }

    private function countCategories(): int
    {
        return Category::query()
            ->where('is_deleted', 0)
            ->count();
    }

    private function countAuthors(): int
    {
        return User::query()
            ->where('is_deleted', 0)
            ->whereIn('role_name', [User::ROLE_AUTHOR, User::ROLE_ADMIN])
            ->count();
    }

    private function countResources(int|string $userId, bool $includeAll): int
    {
        $query = Gallery::query();

        if (! $includeAll) {
            $query->where('created_by_id', $userId);
        }

        return $query->count();
    }

    private function countPendingComments(): int
    {
        return Comment::query()
            ->where('is_approved', 0)
            ->count();
    }

    private function countShorts(?int $userId = null): int
    {
        $query = Short::query();
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        return $query->count();
    }
}
