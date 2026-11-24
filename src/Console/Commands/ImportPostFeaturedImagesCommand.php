<?php

declare(strict_types=1);

namespace EcApi\Console\Commands;

use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use EcApi\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class ImportPostFeaturedImagesCommand extends Command
{
    protected $signature = 'posts:import-featured-images';

    protected $description = 'Import post featured images from public/posts/{post_id}/original into galleries.';

    public function handle(): int
    {
        $processed = 0;
        $imported = 0;
        $skipped = 0;

        Post::query()
            ->select(['id', 'featured_image', 'photo_credit', 'photo_description', 'photo_alt_text'])
            ->whereNotNull('featured_image')
            ->orderBy('id')
            ->chunkById(100, function ($posts) use (&$processed, &$imported, &$skipped): void {
                foreach ($posts as $post) {
                    $processed++;

                    $featuredImage = trim((string) $post->featured_image);

                    if ($featuredImage === '') {
                        $skipped++;
                        $this->line("Post {$post->id}: skipped (featured_image is empty).");

                        continue;
                    }

                    $sourcePath = public_path("posts/{$post->id}/original/{$featuredImage}");
                    if (! File::exists($sourcePath)) {
                        $skipped++;
                        $this->line("Post {$post->id}: skipped (source file not found).");

                        continue;
                    }

                    $year = now()->format('Y');
                    $month = now()->format('m');
                    $relativeDirectory = "uploads/{$year}/{$month}";
                    $disk = Storage::disk('public');
                    $filename = basename($featuredImage);

                    $destinationDirectory = $disk->path($relativeDirectory);
                    $relativePath = "{$relativeDirectory}/{$filename}";

                    if ($disk->exists($relativePath)) {
                        $skipped++;
                        $this->line("Post {$post->id}: skipped (destination file already exists).");

                        continue;
                    }

                    if (! File::exists($destinationDirectory)) {
                        File::makeDirectory($destinationDirectory, 0755, true);
                    }

                    $destinationPath = $destinationDirectory . DIRECTORY_SEPARATOR . $filename;
                    File::copy($sourcePath, $destinationPath);

                    $imageSize = @getimagesize($destinationPath);
                    $width = is_array($imageSize) && isset($imageSize[0]) ? (int) $imageSize[0] : null;
                    $height = is_array($imageSize) && isset($imageSize[1]) ? (int) $imageSize[1] : null;
                    $fileSize = File::size($destinationPath);
                    $mimeType = @mime_content_type($destinationPath) ?: null;
                    $relativePath = "{$relativeDirectory}/{$filename}";

                    DB::transaction(function () use ($post, $fileSize, $mimeType, $relativePath, $filename, $width, $height, $sourcePath, $featuredImage): void {
                        $gallery = Gallery::query()->create([
                            'filename' => $filename,
                            'filepath' => $relativePath,
                            'mime_type' => $mimeType,
                            'file_size' => $fileSize,
                            'height' => $height,
                            'width' => $width,
                        ]);

                        $galleryUsageData = array_filter([
                            'usage_type' => 'posts',
                            'usage_id' => $post->id,
                            'alt_text' => self::normalizeNullableText($post->photo_alt_text),
                            'photo_credit' => self::normalizeNullableText($post->photo_credit),
                            'caption' => self::normalizeNullableText($post->photo_description),
                            'is_primary' => true,
                        ], static fn ($value, string $key): bool => ! in_array($key, ['alt_text', 'photo_credit', 'caption'], true) || $value !== null, ARRAY_FILTER_USE_BOTH);

                        $galleryUsageData['custom_properties'] = [
                            'source_path' => $sourcePath,
                            'source_filename' => $featuredImage,
                        ];

                        $galleryUsage = $gallery->usages()->create($galleryUsageData);

                        GalleryUsage::query()
                            ->where('usage_type', 'posts')
                            ->where('usage_id', $post->id)
                            ->where('id', '!=', $galleryUsage->id)
                            ->update(['is_primary' => false]);
                    });

                    $imported++;
                    $this->line("Post {$post->id}: imported {$featuredImage} as primary gallery usage.");
                }
            });

        $this->info("Done: processed {$processed} posts, imported {$imported}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    private static function normalizeNullableText(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = trim($value);

        if ($normalized === '' || strtolower($normalized) === 'null') {
            return null;
        }

        return $value;
    }
}
