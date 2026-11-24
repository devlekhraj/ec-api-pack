<?php

declare(strict_types=1);

namespace EcApi\Domains\Category\Controllers;

use EcApi\Models\Category;
use EcApi\Models\ArticleVersion;
use EcApi\Models\Post;
use EcApi\Support\NextRevalidator;
use EcApi\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CategoryApiController extends Controller
{
    public function index(Request $request)
    {
        try {
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);

            $query = Category::where('is_deleted', 0)
                ->with(['parent:id,name'])
                ->withCount('posts')
                ->orderByRaw('ISNULL(seq_no), seq_no ASC')
                ->orderByDesc('created_at');

            if ($request->filled('search')) {
                $searchTerm = $request->input('search');
                $query->where(function ($builder) use ($searchTerm) {
                    $builder->where('name', 'like', '%' . $searchTerm . '%')
                        ->orWhere('slug', 'like', '%' . $searchTerm . '%');
                });
            }

            $categories = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $categories->getCollection()->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'seq_no' => $category->seq_no,
                        'parent_id' => $category->parent_id,
                        'parent_name' => $category->parent?->name,
                        'is_active' => (bool) $category->is_active,
                        'created_at' => $category->created_at,
                        'posts_count' => (int) ($category->posts_count ?? 0),
                    ];
                }),
                'meta' => [
                    'total' => $categories->total(),
                    'per_page' => $categories->perPage(),
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                    'from' => $categories->firstItem(),
                    'to' => $categories->lastItem(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response(['error' => $th->getMessage()], 422);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $category = Category::query()
                ->where('is_deleted', 0)
                ->with(['parent:id,name,slug'])
                ->findOrFail($id);

            // Fetch ArticleVersions associated with this category
            $articleVersions = ArticleVersion::query()
                ->whereHas('categories', function ($q) use ($category) {
                    $q->where('categories.id', $category->id);
                })
                ->with([
                    'authors:id,name,username,email,profile_pic,role_name',
                    'categories:id,name,slug',
                    'bannerImage.gallery',
                ])
                ->orderByDesc('published_at')
                ->orderByDesc('created_at')
                ->get();

            $articlesList = collect();

            if ($articleVersions->isNotEmpty()) {
                $articlesList = $articleVersions->map(function ($version) {
                    $firstAuthor = $version->authors->first();
                    $authorAvatar = $firstAuthor?->profile_pic
                        ? (Str::startsWith($firstAuthor->profile_pic, ['http://', 'https://', '/'])
                            ? $firstAuthor->profile_pic
                            : Storage::url($firstAuthor->profile_pic))
                        : null;

                    $bannerUrl = $version->bannerImage?->gallery?->url;

                    return [
                        'id' => $version->article_id ?? $version->id,
                        'version_id' => $version->id,
                        'title' => $version->title,
                        'slug' => $version->slug,
                        'excerpt' => $version->sub_title ?? '',
                        'status' => $version->status,
                        'is_published' => $version->status === ArticleVersion::STATUS_PUBLISHED,
                        'published_at' => $version->published_at?->toIso8601String(),
                        'created_at' => $version->created_at?->toIso8601String(),
                        'author_id' => $firstAuthor?->id,
                        'author_name' => $firstAuthor?->name ?? 'Staff',
                        'author_avatar' => $authorAvatar,
                        'author_slug' => $firstAuthor?->username ?? $firstAuthor?->slug,
                        'thumb' => $bannerUrl,
                        'categories' => $version->categories->map(fn ($cat) => [
                            'id' => $cat->id,
                            'title' => $cat->name,
                            'slug' => $cat->slug,
                        ])->values(),
                    ];
                });
            } else {
                // Fallback to Post table if article versions are not used for this category
                $posts = Post::query()
                    ->whereHas('categories', function ($q) use ($category) {
                        $q->where('categories.id', $category->id);
                    })
                    ->with(['author', 'bannerImage.gallery', 'categories:id,name,slug'])
                    ->orderByDesc('created_at')
                    ->get();

                $articlesList = $posts->map(function ($post) {
                    $bannerUrl = $post->bannerImage?->gallery?->url ?? $post->banner_url;
                    $author = $post->author;
                    $authorAvatar = $author?->profile_pic
                        ? (Str::startsWith($author->profile_pic, ['http://', 'https://', '/'])
                            ? $author->profile_pic
                            : Storage::url($author->profile_pic))
                        : null;

                    return [
                        'id' => $post->id,
                        'version_id' => $post->id,
                        'title' => $post->title ?? $post->name,
                        'slug' => $post->slug,
                        'excerpt' => $post->sub_title ?? $post->meta_description ?? '',
                        'status' => $post->is_published ? 'published' : 'draft',
                        'is_published' => (bool) $post->is_published,
                        'published_at' => $post->published_on,
                        'created_at' => $post->created_at?->toIso8601String(),
                        'author_id' => $author?->id,
                        'author_name' => $author?->name ?? $post->custom_author_name ?? 'Staff',
                        'author_avatar' => $authorAvatar,
                        'author_slug' => $author?->username ?? $author?->slug,
                        'thumb' => $bannerUrl,
                        'categories' => $post->categories->map(fn ($cat) => [
                            'id' => $cat->id,
                            'title' => $cat->name,
                            'slug' => $cat->slug,
                        ])->values(),
                    ];
                });
            }

            // Compute Authors who contributed to this category
            $authorsMap = [];
            foreach ($articlesList as $art) {
                if (!empty($art['author_id'])) {
                    $aId = (int) $art['author_id'];
                    if (!isset($authorsMap[$aId])) {
                        $authorsMap[$aId] = [
                            'id' => $aId,
                            'name' => $art['author_name'],
                            'avatar' => $art['author_avatar'],
                            'slug' => $art['author_slug'] ?? '',
                            'in_category_count' => 0,
                        ];
                    }
                    $authorsMap[$aId]['in_category_count']++;
                }
            }

            // Hydrate extra user info (email, role, total posts count)
            $authorIds = array_keys($authorsMap);
            if (!empty($authorIds)) {
                $userModels = User::query()
                    ->whereIn('id', $authorIds)
                    ->withCount('posts')
                    ->get()
                    ->keyBy('id');

                foreach ($authorsMap as $aId => &$authorData) {
                    $u = $userModels->get($aId);
                    $authorData['email'] = $u?->email ?? '';
                    $authorData['username'] = $u?->username ?? '';
                    $authorData['role'] = $u?->role_name ?? 'Author';
                    $authorData['total_articles_count'] = (int) ($u?->posts_count ?? $authorData['in_category_count']);
                }
                unset($authorData);
            }

            $authorsList = array_values($authorsMap);
            usort($authorsList, fn ($a, $b) => $b['in_category_count'] <=> $a['in_category_count']);

            $totalArticles = $articlesList->count();
            $publishedCount = $articlesList->where('is_published', true)->count();
            $draftCount = $totalArticles - $publishedCount;

            $data = [
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'title' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description ?? '',
                    'parent_id' => $category->parent_id,
                    'parent_name' => $category->parent?->name,
                    'parent' => $category->parent ? [
                        'id' => $category->parent->id,
                        'name' => $category->parent->name,
                        'slug' => $category->parent->slug,
                    ] : null,
                    'seq_no' => $category->seq_no,
                    'is_active' => (bool) $category->is_active,
                    'created_at' => $category->created_at?->toIso8601String(),
                    'updated_at' => $category->updated_at?->toIso8601String(),
                ],
                'stats' => [
                    'total_articles' => $totalArticles,
                    'published_articles' => $publishedCount,
                    'draft_articles' => $draftCount,
                    'authors_count' => count($authorsList),
                ],
                'articles' => $articlesList->values(),
                'authors' => $authorsList,
            ];

            return response()->json(['data' => $data], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Category not found.'], 404);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 422);
        }
    }

    public function getCategories()
    {
        try {
            $categoryList = Category::where('is_deleted', 0)
                ->withCount('posts')
                ->orderByRaw('ISNULL(seq_no), seq_no ASC')
                ->get()
                ->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                    ];
                });

            $authors = User::select('id', 'name', 'slug')->where('is_deleted', 0)->get()->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                ];
            });

            return response(['data' => $categoryList, 'authors' => $authors], 200);
        } catch (\Throwable $th) {
            return response(['error' => $th->getMessage()], 422);
        }
    }

    public function options(Request $request)
    {
        $query = Category::query()
            ->where('is_deleted', 0);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        $categories = $query
            ->orderByRaw('ISNULL(seq_no), seq_no ASC')
            ->orderBy('name')
            ->get(['id', 'name']);

        $data = $categories->map(fn ($category) => [
            'value' => $category->id,
            'text' => $category->name,
        ])->values();

        return response()->json(['data' => $data], 200);
    }

    public function lookups()
    {
        try {
            $categories = Category::query()
                ->where('is_deleted', 0)
                ->withCount([
                    'articleVersions as published_articles_count' => function ($query): void {
                        $query->where('article_versions.status', ArticleVersion::STATUS_PUBLISHED);
                    },
                ])
                ->orderByRaw('ISNULL(seq_no), seq_no ASC')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'published_articles_count' => (int) ($category->published_articles_count ?? 0),
                ])
                ->values();

            return response()->json(['data' => $categories], 200);
        } catch (\Throwable $th) {
            return response(['error' => $th->getMessage()], 422);
        }
    }

    public function store(Request $request)
    {
        $id = $request->input('id');

        if ($request->has('parent_id') && ($request->input('parent_id') === '' || $request->input('parent_id') === 'null')) {
            $request->merge(['parent_id' => null]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:500',
        ]);

        $parentId = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;
        if ($id && $parentId === (int) $id) {
            $parentId = null;
        }

        if ($id) {
            $category = Category::findOrFail($id);

            $slug = $request->filled('slug')
                ? Str::slug((string) $request->input('slug'))
                : $category->slug;

            if ($slug !== $category->slug && Category::where('slug', $slug)->where('id', '!=', $category->id)->where('is_deleted', 0)->exists()) {
                $count = 1;
                $origSlug = $slug;
                while (Category::where('slug', $slug)->where('id', '!=', $category->id)->where('is_deleted', 0)->exists()) {
                    $slug = $origSlug . '-' . $count;
                    $count++;
                }
            }

            $isActive = $request->has('is_active')
                ? filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN)
                : $category->is_active;

            $category->update([
                'name' => $validated['name'],
                'slug' => $slug,
                'parent_id' => $parentId,
                'is_active' => $isActive,
                'seq_no' => $request->seq_no ?? $category->seq_no,
                'description' => $validated['description'] ?? $category->description ?? '',
            ]);

            return response()->json([
                'message' => 'Category updated successfully!',
                'category' => $category,
            ], 200);
        }

        $slug = $request->filled('slug')
            ? Str::slug((string) $request->input('slug'))
            : Str::slug($validated['name']);

        if (Category::where('slug', $slug)->where('is_deleted', 0)->exists()) {
            $count = 1;
            $origSlug = $slug;
            while (Category::where('slug', $slug)->where('is_deleted', 0)->exists()) {
                $slug = $origSlug . '-' . $count;
                $count++;
            }
        }

        $isActive = $request->has('is_active')
            ? filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN)
            : true;

        $category = Category::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'parent_id' => $parentId,
            'is_active' => $isActive,
            'seq_no' => $request->seq_no ?? null,
            'description' => $validated['description'] ?? '',
        ]);

        return response()->json([
            'message' => 'Category created successfully!',
            'category' => $category,
        ], 201);
    }

    public function updateCategoryStatus(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        $category->loadCount('posts');
        $category->is_active = $request->is_active;
        $category->save();

        return response()->json(['message' => 'Category status updated successfully.', 'data' => $category]);
    }

    public function destroy(Request $request, $id)
    {
        try {
            $category = Category::findOrFail($id);
            $category->is_deleted = 1;
            $category->deleted_at = now();
            $category->save();

            return response()->json(['message' => 'Category deleted successfully.', 'data' => $category], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
                'message' => 'Failed to Delete',
            ], 422);
        }
    }
}
