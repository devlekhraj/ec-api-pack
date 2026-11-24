<?php

declare(strict_types=1);

namespace EcApi\Domains\Short\Controllers;

use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use EcApi\Models\Short;
use EcApi\Support\NextRevalidator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ShortApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Short::query()
            ->with([
                'author:id,name,username,email,profile_pic',
                'mediaUsage.gallery',
            ]);

        // Search filter
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // Status filter
        $status = $request->query('status');
        if ($status && in_array($status, [Short::STATUS_PUBLISHED, Short::STATUS_DRAFT], true)) {
            $query->where('status', $status);
        }

        // Author filter
        if ($authorId = $request->query('author_id')) {
            $query->where('user_id', $authorId);
        }

        $totalCount = (clone $query)->count();
        $publishedCount = Short::query()->where('status', Short::STATUS_PUBLISHED)->count();
        $draftCount = Short::query()->where('status', Short::STATUS_DRAFT)->count();

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $shorts = $query->orderBy('sort_order', 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $shorts->items(),
            'meta' => [
                'current_page' => $shorts->currentPage(),
                'last_page' => $shorts->lastPage(),
                'per_page' => $shorts->perPage(),
                'total' => $shorts->total(),
            ],
            'counts' => [
                'total' => $totalCount,
                'published' => $publishedCount,
                'draft' => $draftCount,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:shorts,slug'],
            'user_id' => ['nullable', 'exists:users,id'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'video_url' => ['nullable', 'string', 'max:2048'],
            'redirect_url' => ['nullable', 'string', 'max:2048'],
            'status' => ['nullable', 'string', Rule::in([Short::STATUS_PUBLISHED, Short::STATUS_DRAFT])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'gallery_id' => ['nullable', 'exists:galleries,id'],
            'published_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $short = DB::transaction(function () use ($validated, $request): Short {
            $status = $validated['status'] ?? Short::STATUS_DRAFT;
            $slug = ! empty($validated['slug'])
                ? $validated['slug']
                : Short::generateUniqueSlug($validated['title']);

            $userId = ! empty($validated['user_id'])
                ? (int) $validated['user_id']
                : ($request->user()?->id ?? \EcApi\Models\User::query()->value('id'));

            $publishedAt = ! empty($validated['published_at'])
                ? $validated['published_at']
                : ($status === Short::STATUS_PUBLISHED ? now() : null);

            $short = Short::create([
                'title' => $validated['title'],
                'slug' => $slug,
                'user_id' => $userId,
                'description' => $validated['description'] ?? null,
                'location' => $validated['location'] ?? null,
                'video_url' => $validated['video_url'] ?? null,
                'redirect_url' => $validated['redirect_url'] ?? null,
                'status' => $status,
                'sort_order' => $validated['sort_order'] ?? 0,
                'published_at' => $publishedAt,
                'meta' => $validated['meta'] ?? null,
            ]);

            if (! empty($validated['gallery_id'])) {
                $this->attachGalleryThumbnail($short, (int) $validated['gallery_id'], $request->user()?->id);
            }

            return $short;
        });

        $short->load(['author:id,name,username,email,profile_pic', 'mediaUsage.gallery']);

        return response()->json([
            'message' => 'Short created successfully.',
            'data' => $short,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $short = Short::query()
            ->with(['author:id,name,username,email,profile_pic', 'mediaUsage.gallery'])
            ->findOrFail($id);

        return response()->json([
            'data' => $short,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $short = Short::findOrFail($id);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('shorts', 'slug')->ignore($short->id)],
            'user_id' => ['nullable', 'exists:users,id'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'video_url' => ['nullable', 'string', 'max:2048'],
            'redirect_url' => ['nullable', 'string', 'max:2048'],
            'status' => ['nullable', 'string', Rule::in([Short::STATUS_PUBLISHED, Short::STATUS_DRAFT])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'gallery_id' => ['nullable', 'exists:galleries,id'],
            'published_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($short, $validated, $request): void {
            $status = $validated['status'] ?? $short->status;
            $slug = ! empty($validated['slug'])
                ? $validated['slug']
                : Short::generateUniqueSlug($validated['title'], $short->id);

            $publishedAt = $short->published_at;
            if (array_key_exists('published_at', $validated)) {
                $publishedAt = $validated['published_at'] ?: null;
            } elseif ($status === Short::STATUS_PUBLISHED && ! $publishedAt) {
                $publishedAt = now();
            }

            $userId = ! empty($validated['user_id'])
                ? (int) $validated['user_id']
                : ($short->user_id ?: ($request->user()?->id ?? \EcApi\Models\User::query()->value('id')));

            $dataToUpdate = [
                'title' => $validated['title'],
                'slug' => $slug,
                'user_id' => $userId,
                'description' => $validated['description'] ?? null,
                'location' => $validated['location'] ?? null,
                'video_url' => $validated['video_url'] ?? null,
                'redirect_url' => $validated['redirect_url'] ?? null,
                'status' => $status,
                'sort_order' => $validated['sort_order'] ?? $short->sort_order,
                'published_at' => $publishedAt,
            ];

            if (array_key_exists('meta', $validated)) {
                $dataToUpdate['meta'] = $validated['meta'];
            }

            $short->update($dataToUpdate);

            if (array_key_exists('gallery_id', $validated)) {
                if (! empty($validated['gallery_id'])) {
                    $this->attachGalleryThumbnail($short, (int) $validated['gallery_id'], $request->user()?->id);
                } else {
                    $this->removeGalleryThumbnail($short);
                }
            }
        });

        $short->load(['author:id,name,username,email,profile_pic', 'mediaUsage.gallery']);

        return response()->json([
            'message' => 'Short updated successfully.',
            'data' => $short,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $short = Short::findOrFail($id);

        DB::transaction(function () use ($short): void {
            $this->removeGalleryThumbnail($short);
            $short->delete();
        });

        return response()->json([
            'message' => 'Short deleted successfully.',
        ]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $short = Short::findOrFail($id);

        $newStatus = $short->status === Short::STATUS_PUBLISHED
            ? Short::STATUS_DRAFT
            : Short::STATUS_PUBLISHED;

        $short->status = $newStatus;
        if ($newStatus === Short::STATUS_PUBLISHED && ! $short->published_at) {
            $short->published_at = now();
        }
        $short->save();

        return response()->json([
            'message' => "Short status changed to {$newStatus}.",
            'data' => [
                'id' => $short->id,
                'status' => $short->status,
                'published_at' => $short->published_at,
            ],
        ]);
    }

    private function attachGalleryThumbnail(Short $short, int $galleryId, ?int $userId): void
    {
        // Check if primary thumbnail usage exists
        $existing = GalleryUsage::query()
            ->where('usage_type', 'shorts')
            ->where('usage_id', $short->id)
            ->where('collection', 'thumbnail')
            ->first();

        if ($existing) {
            $existing->update([
                'gallery_id' => $galleryId,
                'is_primary' => true,
                'created_by_id' => $userId ?? $existing->created_by_id,
            ]);
        } else {
            GalleryUsage::create([
                'gallery_id' => $galleryId,
                'usage_id' => $short->id,
                'usage_type' => 'shorts',
                'collection' => 'thumbnail',
                'is_primary' => true,
                'created_by_id' => $userId,
            ]);
        }
    }

    private function removeGalleryThumbnail(Short $short): void
    {
        GalleryUsage::query()
            ->where('usage_type', 'shorts')
            ->where('usage_id', $short->id)
            ->delete();
    }
}
