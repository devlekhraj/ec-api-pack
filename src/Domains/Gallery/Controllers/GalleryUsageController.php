<?php

declare(strict_types=1);

namespace EcApi\Domains\Gallery\Controllers;

use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class GalleryUsageController extends Controller
{
    /**
     * Update presentation metadata (alt_text, caption, photo_credit) on an
     * existing GalleryUsage record (gallery-usages/{id}/update route).
     */
    public function update(Request $request, int $usageId): JsonResponse
    {
        $request->validate([
            'id'           => ['required', 'exists:gallery_usages,id', 'integer'],
            'alt_text'     => ['nullable', 'string', 'max:255'],
            'caption'      => ['nullable', 'string', 'max:255'],
            'photo_credit' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $usage = GalleryUsage::find($usageId);

            if (! $usage) {
                return response()->json(['message' => 'Record not found'], 404);
            }

            DB::transaction(function () use ($usage, $request): void {
                $usage->update([
                    'alt_text'     => $request->input('alt_text'),
                    'caption'      => $request->input('caption'),
                    'photo_credit' => $request->input('photo_credit'),
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Image usage updated successfully',
                'data'    => [
                    'alt_text'     => $usage->alt_text,
                    'caption'      => $usage->caption,
                    'photo_credit' => $usage->photo_credit,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update image usage',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update a GalleryUsage record for a gallery image
     * (images/{gallery_id}/usage-save route).
     */
    public function save(Request $request, int $galleryId): JsonResponse
    {
        $validated = $request->validate([
            'usage_type'        => ['required', 'string', 'max:120'],
            'usage_id'          => ['required', 'integer', 'min:1'],
            'collection'        => ['nullable', 'string', Rule::in(GalleryUsage::COLLECTIONS)],
            'is_primary'        => ['nullable', 'boolean'],
            'seq_no'            => ['nullable', 'integer', 'min:0'],
            'alt_text'          => ['nullable', 'string', 'max:255'],
            'photo_credit'      => ['nullable', 'string', 'max:255'],
            'caption'           => ['nullable', 'string', 'max:255'],
            'title'             => ['nullable', 'string', 'max:255'],
            'description'       => ['nullable', 'string', 'max:255'],
            'custom_properties' => ['nullable', 'array'],
        ]);

        try {
            $createdById = $request->user()?->id;

            $image = Gallery::find($galleryId);

            if (! $image) {
                return response()->json(['message' => 'Image not found'], 404);
            }

            $collection = (string) ($validated['collection'] ?? GalleryUsage::COLLECTION_DEFAULT);
            $usageType  = (string) $validated['usage_type'];
            $usageId    = (int) $validated['usage_id'];
            $hasPrimaryInput = array_key_exists('is_primary', $validated);
            $isPrimary  = (bool) ($validated['is_primary'] ?? false);

            $usageValues = [
                'created_by_id' => $createdById,
            ];

            if ($hasPrimaryInput) {
                $usageValues['is_primary'] = $isPrimary;
            }

            foreach ([
                'seq_no',
                'alt_text',
                'photo_credit',
                'caption',
                'title',
                'description',
                'custom_properties',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $usageValues[$field] = $validated[$field];
                }
            }

            $usage = DB::transaction(function () use (
                $collection,
                $galleryId,
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

                // Single-slot collections update their usage slot; multi-image
                // collections update the exact gallery attachment.
                $identity = [
                    'usage_type' => $usageType,
                    'usage_id'   => $usageId,
                    'collection' => $collection,
                ];

                if (GalleryUsage::isSingleSlotCollection($collection)) {
                    $usageValues['gallery_id'] = $galleryId;
                } else {
                    $identity['gallery_id'] = $galleryId;
                }

                $usage = GalleryUsage::updateOrCreate(
                    $identity,
                    $usageValues,
                );

                if ($hasPrimaryInput && $isPrimary) {
                    (clone $contextQuery)
                        ->where('id', '!=', $usage->id)
                        ->where('is_primary', true)
                        ->update(['is_primary' => false]);
                }

                return $usage;
            });

            return response()->json([
                'message' => 'Image usage saved successfully',
                'data'    => $usage,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to save image usage',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set a GalleryUsage as the primary for its entity+collection context.
     *
     * All other usages for the same (usage_type, usage_id, collection) are
     * demoted to is_primary = false. They are NOT deleted.
     * (image-usages/{id}/make-primary route)
     */
    public function makePrimary(int $id): JsonResponse
    {
        try {
            $usage = GalleryUsage::find($id);

            if (! $usage) {
                return response()->json(['message' => 'Image usage not found'], 404);
            }

            DB::transaction(function () use ($usage): void {
                $contextQuery = GalleryUsage::query()
                    ->where('usage_id', $usage->usage_id)
                    ->where('usage_type', $usage->usage_type)
                    ->where('collection', $usage->collection);

                $contextQuery->lockForUpdate()->get(['id']);

                // Demote all sibling usages — do NOT delete them.
                (clone $contextQuery)
                    ->where('id', '!=', $usage->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);

                if (! $usage->is_primary) {
                    $usage->is_primary = true;
                    $usage->save();
                }
            });

            return response()->json([
                'message' => 'Image usage set as primary successfully',
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to set image usage as primary',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a single GalleryUsage record.
     * (image-usages/{id}/remove route)
     */
    public function remove(int $id): JsonResponse
    {
        try {
            $usage = GalleryUsage::find($id);

            if (! $usage) {
                return response()->json(['message' => 'Image usage not found'], 404);
            }

            $usage->delete();

            return response()->json(['message' => 'Image usage removed successfully'], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to remove image usage',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
