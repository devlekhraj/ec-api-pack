<?php

declare(strict_types=1);

namespace EcApi\Domains\Gallery\Controllers;

use EcApi\Domains\Gallery\GalleryUploadService;
use EcApi\Domains\Gallery\Resources\GalleryResource;
use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

final class GalleryController extends Controller
{
    public function __construct(
        protected GalleryUploadService $galleryUploadService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page    = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);

        $query = Gallery::query();

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where('filename', 'like', '%' . $search . '%');
        }

        $galleries = $query
            ->withCount('usages')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data'    => GalleryResource::collection($galleries->getCollection())->resolve(),
            'meta'    => [
                'total'        => $galleries->total(),
                'per_page'     => $galleries->perPage(),
                'current_page' => $galleries->currentPage(),
                'last_page'    => $galleries->lastPage(),
                'from'         => $galleries->firstItem(),
                'to'           => $galleries->lastItem(),
            ],
        ], 200);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $gallery = Gallery::find($id);

        if (! $gallery) {
            return response()->json(['message' => 'Gallery not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $gallery,
        ], 200);
    }

    /**
     * Upload an image and associate it with a usage context (gallery-upload route).
     *
     * Accepts either an existing gallery image (source=existing) or a new file
     * upload (source=upload) and creates/updates a GalleryUsage record.
     */
    public function upload(Request $request): JsonResponse
    {
        $createdById = $request->user()?->id;

        $validated = $request->validate([
            'usage_type'        => ['required', 'string', 'max:120'],
            'usage_id'          => ['required', 'integer', 'min:1'],
            'source'            => ['required', 'string', 'in:existing,upload'],
            'gallery_id'        => ['nullable', 'integer', 'exists:galleries,id', 'required_if:source,existing'],
            'collection'        => ['nullable', 'string', Rule::in(GalleryUsage::COLLECTIONS)],
            'custom_properties' => ['nullable', 'array'],
            'is_primary'        => ['nullable', 'boolean'],
            'image'             => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120', 'required_if:source,upload'],
        ]);

        $source     = (string) $validated['source'];
        $usageType  = (string) $validated['usage_type'];
        $usageId    = (int) $validated['usage_id'];
        $collection = (string) ($validated['collection'] ?? GalleryUsage::COLLECTION_DEFAULT);
        $hasPrimaryInput = array_key_exists('is_primary', $validated);
        $isPrimary  = (bool) ($validated['is_primary'] ?? false);

        try {
            if ($source === 'existing') {
                $gallery = Gallery::find((int) $validated['gallery_id']);

                if (! $gallery) {
                    return response()->json(['message' => 'Gallery image not found.'], 404);
                }
            } else {
                $image = $request->file('image');

                if (! $image) {
                    return response()->json(['message' => 'Image is required for upload source.'], 422);
                }

                $gallery = $collection === GalleryUsage::COLLECTION_SOCIAL
                    ? $this->galleryUploadService->uploadSocial($image, $createdById)
                    : $this->galleryUploadService->uploadStandard($image, $createdById);
            }

            $usageValues = [];

            if (array_key_exists('custom_properties', $validated)) {
                $usageValues['custom_properties'] = $validated['custom_properties'];
            }

            $galleryUsage = DB::transaction(function () use (
                $collection,
                $createdById,
                $gallery,
                $hasPrimaryInput,
                $isPrimary,
                $usageId,
                $usageType,
                $usageValues,
            ): GalleryUsage {
                $contextQuery = GalleryUsage::query()
                    ->where('usage_type', $usageType)
                    ->where('usage_id', $usageId)
                    ->where('collection', $collection);

                $contextQuery->lockForUpdate()->get(['id']);

                if ($isPrimary) {
                    $existingPrimary = (clone $contextQuery)
                        ->where('is_primary', true)
                        ->first();

                    // Carry over presentation metadata from the outgoing primary.
                    if ($existingPrimary && $existingPrimary->gallery_id !== $gallery->id) {
                        $usageValues = array_merge([
                            'caption'      => $existingPrimary->caption,
                            'alt_text'     => $existingPrimary->alt_text,
                            'photo_credit' => $existingPrimary->photo_credit,
                        ], $usageValues);
                    }
                }

                $saveValues = array_merge($usageValues, [
                    'created_by_id' => $createdById,
                ]);

                if ($hasPrimaryInput) {
                    $saveValues['is_primary'] = $isPrimary;
                }

                $identity = [
                    'usage_type' => $usageType,
                    'usage_id'   => $usageId,
                    'collection' => $collection,
                ];

                if (GalleryUsage::isSingleSlotCollection($collection)) {
                    $saveValues['gallery_id'] = $gallery->id;
                } else {
                    $identity['gallery_id'] = $gallery->id;
                }

                $galleryUsage = GalleryUsage::updateOrCreate(
                    $identity,
                    $saveValues,
                );

                if ($isPrimary) {
                    (clone $contextQuery)
                        ->where('id', '!=', $galleryUsage->id)
                        ->where('is_primary', true)
                        ->update([
                            'caption'      => null,
                            'alt_text'     => null,
                            'photo_credit' => null,
                            'is_primary'   => false,
                        ]);
                }

                return $galleryUsage;
            });

            return response()->json([
                'success' => true,
                'message' => 'Image updated',
                'data'    => $galleryUsage,
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Gallery upload failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);

            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    /**
     * Simple upload with no usage context (upload-image route).
     *
     * Stores the image in the gallery and returns the gallery record + URL.
     * The caller is responsible for creating a GalleryUsage if needed.
     */
    public function store(Request $request): JsonResponse
    {
        $createdById = $request->user()?->id;

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        $image = $request->file('image');

        if (! $image) {
            return response()->json(['message' => 'Image is required.'], 422);
        }

        try {
            $gallery = $this->galleryUploadService->uploadStandard($image, $createdById);

            return response()->json([
                'success' => true,
                'data'    => (new GalleryResource($gallery))->resolve(),
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Gallery store failed', [
                'error' => $th->getMessage(),
                'file'  => $th->getFile(),
                'line'  => $th->getLine(),
            ]);

            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
}
