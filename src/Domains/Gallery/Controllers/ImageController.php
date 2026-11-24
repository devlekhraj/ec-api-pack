<?php

declare(strict_types=1);

namespace EcApi\Domains\Gallery\Controllers;

use EcApi\Domains\Shared\Resources\ImageResource;
use EcApi\Models\Gallery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ImageController extends Controller
{
    /**
     * Paginated list of gallery images (no usage metadata).
     *
     * Admins/editors see all images. Other roles see only their own uploads.
     */
    public function imageList(Request $request): JsonResponse
    {
        $query = Gallery::orderBy('id', 'desc');
        $user  = $request->user();

        if (! $user?->hasAnyRoles(['admin', 'editor'])) {
            $query->where('created_by_id', $user?->id);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('filename', 'like', '%' . $search . '%')
                  ->orWhere('filepath', 'like', '%' . $search . '%');
            });
        }

        $images = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => ImageResource::collection($images),
            'meta'    => [
                'total'        => $images->total(),
                'per_page'     => $images->perPage(),
                'current_page' => $images->currentPage(),
                'last_page'    => $images->lastPage(),
                'from'         => $images->firstItem(),
                'to'           => $images->lastItem(),
            ],
        ], 200);
    }

    /**
     * Paginated list of gallery images with usage count attached.
     */
    public function imageListWithUsage(Request $request): JsonResponse
    {
        $query = Gallery::withCount('usages')->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('filename', 'like', '%' . $search . '%')
                  ->orWhere('filepath', 'like', '%' . $search . '%');
            });
        }

        $images = $query->paginate(36);

        return response()->json([
            'success' => true,
            'data'    => $images->items(),
            'meta'    => [
                'total'        => $images->total(),
                'per_page'     => $images->perPage(),
                'current_page' => $images->currentPage(),
                'last_page'    => $images->lastPage(),
                'from'         => $images->firstItem(),
                'to'           => $images->lastItem(),
            ],
        ], 200);
    }

    /**
     * Serve an image file directly (public, no auth).
     *
     * Falls back to the logo if the file is not found on disk.
     * On production, images are served by Nginx/CDN — this route is primarily
     * used in local development or as a safety fallback.
     */
    public function getImage(string $filename): mixed
    {
        $fallback         = public_path('images/logo.png');
        $fallbackMimeType = @mime_content_type($fallback) ?: 'image/png';

        $file = Gallery::where('filename', $filename)->first();

        if (! $file) {
            return response()->file($fallback, ['Content-Type' => $fallbackMimeType]);
        }

        $disk         = (string) ($file->storage_disk ?: config('filesystems.image_fallback_disk', 'public'));
        $relativePath = ltrim((string) $file->filepath, '/');

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage  = Storage::disk($disk);

        if (! $storage->exists($relativePath)) {
            return response()->file($fallback, ['Content-Type' => $fallbackMimeType]);
        }

        try {
            return $storage->response($relativePath);
        } catch (\Throwable) {
            return response()->file($fallback, ['Content-Type' => $fallbackMimeType]);
        }
    }
}
