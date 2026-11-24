<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Controllers;

use EcApi\Models\Category;
use EcApi\Domains\Post\Actions\PostCreateAction;
use EcApi\Domains\Post\Actions\PostDeleteAction;
use EcApi\Domains\Post\Actions\PostDetailAction;
use EcApi\Domains\Post\Actions\PostListAction;
use EcApi\Domains\Post\Actions\PostUpdateAction;
use EcApi\Domains\Post\DTOs\PostCreateData;
use EcApi\Domains\Post\DTOs\PostUpdateData;
use EcApi\Models\Post;
use EcApi\Domains\Post\Requests\StorePostRequest;
use EcApi\Domains\Post\Requests\UpdatePostRequest;
use EcApi\Domains\Post\Resources\PostDetailResource;
use EcApi\Domains\Post\Resources\PostResource;
use App\Http\Controllers\Controller;
use EcApi\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Intervention\Image\ImageManager;

final class PostController extends Controller
{
    public function __construct(
        private readonly PostListAction $postListAction,
        private readonly PostDetailAction $postDetailAction,
        private readonly PostCreateAction $postCreateAction,
        private readonly PostUpdateAction $postUpdateAction,
        private readonly PostDeleteAction $postDeleteAction,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPageParam = (int) $request->query('per_page', 15);
        $search = $request->query('search');
        $categoryId = $request->query('category_id');
        $result = $this->postListAction->execute(is_string($search) ? $search : null, $perPageParam, $categoryId);

        return response()->json([
            'data' => PostResource::collection($result['items'] ?? []),
            'meta' => $result['meta'] ?? null,
        ]);
    }

    // public function show(string $id): JsonResponse
    // {
    //     $post = $this->postDetailAction->execute($id);

    //     return response()->json([
    //         'data' => new PostResource($post),
    //         'success' => true,
    //     ], 200);
    // }
    public function show(string $id): JsonResponse
    {
        $post = $this->postDetailAction->execute($id);

        return response()->json([
            'data' => new PostDetailResource($post),
            'success' => true,
        ], 200);
    }

    public function fetchPublishedArticle(string $id): JsonResponse
    {
        $post = $this->postDetailAction->execute($id);

        return response()->json([
            'data' => new PostDetailResource($post),
            'success' => true,
        ], 200);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $dto = PostCreateData::fromArray($request->validated());
        $post = $this->postCreateAction->execute($dto);

        return response()->json([
            'message' => 'Post created successfully.',
            'data' => new PostResource($post),
            'success' => true,
        ], 201);
    }

    public function update(UpdatePostRequest $request, string $id): JsonResponse
    {
        try {
            $dto = PostUpdateData::fromArray($request->validated());

            $post = $this->postUpdateAction->execute(
                Post::query()->findOrFail($id),
                $dto
            );

            return response()->json([
                'message' => 'Post updated successfully.',
                'data' => new PostResource($post),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to update post.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        $this->postDeleteAction->execute(Post::query()->findOrFail($id));

        return response()->json([
            'message' => 'Post deleted successfully.',
            'success' => true,
        ], 200);
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->selectRaw('id as value, name as text')
            ->where('is_deleted', 0)
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function authors(Request $request): JsonResponse
    {
        $authors = User::query()
            ->selectRaw('id as value, name as text')
            ->where('role_name', User::ROLE_AUTHOR)
            ->get();

        return response()->json(['data' => $authors]);
    }

    public function updateSeo(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:255', Rule::unique('posts', 'slug')->ignore($id)],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
        ]);

        $post = Post::query()->findOrFail($id);
        $post->slug = $validated['slug'];
        $post->meta_title = $validated['meta_title'] ?? null;
        $post->meta_description = $validated['meta_description'] ?? null;
        $post->meta_keywords = $validated['meta_keywords'] ?? null;
        $post->save();
        $post->load('author', 'categories');

        return response()->json([
            'message' => 'SEO settings updated successfully.',
            'data' => new PostResource($post),
        ]);
    }

    public function togglePublish(string $id, Request $request): JsonResponse
    {
        $post = Post::query()->findOrFail($id);

        if ($request->has('is_home_featured')) {
            if ($request->boolean('is_home_featured')) {
                Post::query()->where('id', '!=', $id)->update(['is_home_featured' => 0]);
            }
            $post->is_home_featured = $request->boolean('is_home_featured');
        } elseif ($request->has('is_published')) {
            $post->is_published = $request->boolean('is_published');
            if (! $post->published_on) {
                $post->published_on = now();
            }
        } elseif ($request->has('is_featured')) {
            if ($request->boolean('is_featured')) {
                Post::query()
                    ->where('category_id', $post->category_id)
                    ->where('id', '!=', $post->id)
                    ->update(['is_featured' => 0]);
                $post->is_featured = 1;
            } else {
                $post->is_featured = 0;
            }
        }

        $post->save();
        $post->load('author', 'categories');

        return response()->json([
            'message' => 'Post updated successfully.',
            'data' => new PostResource($post),
        ]);
    }

    public function updatePostStatus(string $id, Request $request): JsonResponse
    {
        return $this->togglePublish($id, $request);
    }

    public function publishPost(string $id, Request $request): JsonResponse
    {
        $post = Post::query()->findOrFail($id);

        try {
            if ($request->input('status') === 'publish') {
                $post->is_published = 1;
                $post->status = 'published';

                if (! $post->published_on) {
                    $post->published_on = now();
                }
            } else {
                $post->is_published = 0;
                $post->status = 'draft';
            }

            $post->save();
            $post->load('author', 'categories');

            return response()->json([
                'message' => 'Post updated successfully.',
                'data' => new PostResource($post),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to update post.',
                'data' => new PostResource($post),
            ], 422);
        }
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'name' => ['required', 'string'],
            'post_id' => ['required', 'integer', 'exists:posts,id'],
        ]);

        $postId = (int) $validated['post_id'];
        $image = $request->file('image');

        if (! $image instanceof UploadedFile) {
            return response()->json(['error' => 'No image uploaded'], 400);
        }

        $filename = pathinfo($this->generateSeoImageName($validated['name']), PATHINFO_FILENAME) . '.webp';
        $baseDirectory = "posts/{$postId}";
        $imageManager = new ImageManager('gd');

        foreach (['original', 'small', 'medium', 'large', 'xlarge'] as $folder) {
            Storage::disk('public')->makeDirectory("{$baseDirectory}/{$folder}");
        }

        $originalPath = "{$baseDirectory}/original/{$filename}";
        $imagePathname = $image->getPathname();
        $imageManager->decodePath($imagePathname)->save(storage_path("app/public/{$originalPath}"));

        $imageUrls = ['original' => Storage::url("{$baseDirectory}/original/{$filename}")];
        foreach (['small' => 480, 'medium' => 768, 'large' => 1200, 'xlarge' => 1600] as $sizeName => $width) {
            $imagePath = "{$baseDirectory}/{$sizeName}/{$filename}";
            $imageManager->decodePath($imagePathname)
                ->resize($width, null)
                ->save(storage_path("app/public/{$imagePath}"));
            $imageUrls[$sizeName] = Storage::url($imagePath);
        }

        return response()->json([
            'urls' => $imageUrls,
            'file_name' => $filename,
        ]);
    }

    public function banner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'post_id' => ['required', 'integer', 'exists:posts,id'],
        ]);

        $post = Post::query()->findOrFail((int) $validated['post_id']);
        $image = $request->file('image');
        $imageUrls = [];
        $originalFilename = null;
        $imageManager = new ImageManager('gd');

        if ($image instanceof UploadedFile) {
            $title = $post->title ?? 'image';
            $slug = Str::slug($title);
            $random = date('Y-m-d') . '-' . substr(md5(uniqid('', true)), 0, 4);
            $originalExtension = $image->getClientOriginalExtension();
            $originalFilename = "{$slug}-{$random}.{$originalExtension}";
            $webpFilename = "{$slug}-{$random}.webp";
            $baseDirectory = "posts/{$post->id}";

            foreach (['original', 'small', 'medium', 'large', 'xlarge'] as $folder) {
                Storage::disk('public')->makeDirectory("{$baseDirectory}/{$folder}");
            }

            $originalPath = "{$baseDirectory}/original/{$originalFilename}";
            Storage::disk('public')->put($originalPath, file_get_contents($image->getPathname()));
            $imageUrls['original'] = Storage::url($originalPath);

            $imagePathname = $image->getPathname();
            foreach (['small' => 480, 'medium' => 768, 'large' => 1200, 'xlarge' => 1600] as $sizeName => $width) {
                $imagePath = "{$baseDirectory}/{$sizeName}/{$webpFilename}";
                $imageManager->decodePath($imagePathname)
                    ->resize($width, null)
                    ->save(storage_path("app/public/{$imagePath}"));
                $imageUrls[$sizeName] = Storage::url($imagePath);
            }

            $post->featured_image = $originalFilename;
        }

        $post->save();
        $this->setDirectoryPermissions();

        return response()->json([
            'urls' => $imageUrls,
            'file_name' => $originalFilename,
            'post' => new PostResource($post),
        ]);
    }

    private function generateSeoImageName(string $sentence, int $maxWords = 6): string
    {
        $stopWords = ['a', 'an', 'the', 'to', 'for', 'of', 'and', 'is', 'in', 'on', 'with', 'needed'];

        $sentence = strtolower($sentence);
        $sentence = preg_replace('/[^a-z0-9\s-]/', '', $sentence) ?? '';
        $words = explode(' ', $sentence);
        $filteredWords = array_diff($words, $stopWords);
        $seoWords = array_slice(array_values($filteredWords), 0, $maxWords);
        $random = date('Y-m-d') . '-' . substr(md5(uniqid((string) time(), true)), 0, 4);

        return implode('-', $seoWords) . '-' . $random . '.webp';
    }

    private function setDirectoryPermissions(): void
    {
        foreach ([
            storage_path('app/storage'),
            storage_path('app/bootstrap/cache'),
            public_path('storage'),
        ] as $dir) {
            @exec("chmod -R 775 {$dir}");
        }
    }
}
