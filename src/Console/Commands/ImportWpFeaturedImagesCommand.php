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
use Illuminate\Support\Str;

final class ImportWpFeaturedImagesCommand extends Command
{
    protected $signature = 'posts:import-wp-featured-images';

    protected $description = 'Import featured images from WordPress URLs into galleries for posts 1 through 273.';

    public function handle(): int
    {
        $processed = 0;
        $imported = 0;
        $skipped = 0;

        Post::query()
            // ->select(['id', 'featured_image'])
            ->whereBetween('id', [1, 273])
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

                    $parsed = $this->parseFeaturedImageUrl($featuredImage);
                    if ($parsed === null) {
                        $skipped++;
                        $this->line("Post {$post->id}: skipped (not a supported WordPress URL).");

                        continue;
                    }

                    [$year, $month, $filename, $sourceRelativePath] = $parsed;
                    $sourcePath = public_path($sourceRelativePath);
                    if (! File::exists($sourcePath)) {
                        $skipped++;
                        $this->line("Post {$post->id}: skipped (source file not found).");

                        continue;
                    }

                    $relativeDirectory = "gallery/{$year}/{$month}";
                    $disk = Storage::disk('public');
                    $relativePath = "{$relativeDirectory}/{$filename}";
                    $existingGallery = Gallery::query()->where('filepath', $relativePath)->first();
                    $destinationExists = $disk->exists($relativePath);

                    if ($existingGallery !== null) {
                        $existingUsage = GalleryUsage::query()
                            ->where('gallery_id', $existingGallery->id)
                            ->where('usage_type', 'posts')
                            ->where('usage_id', $post->id)
                            ->exists();

                        if ($existingUsage) {
                            $skipped++;
                            $this->line("Post {$post->id}: skipped (already imported).");

                            continue;
                        }

                        $this->storePostUsage($post, $existingGallery);

                        $imported++;
                        $this->line("Post {$post->id}: linked existing gallery to post usage.");

                        continue;
                    }

                    if ($destinationExists) {
                        $skipped++;
                        $this->line("Post {$post->id}: skipped (destination file already exists but gallery is missing).");

                        continue;
                    }

                    $destinationDirectory = $disk->path($relativeDirectory);

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

                    DB::transaction(function () use ($post, $fileSize, $mimeType, $relativePath, $filename, $width, $height): void {
                        $gallery = Gallery::query()->create([
                            'filename' => $filename,
                            'filepath' => $relativePath,
                            'mime_type' => $mimeType,
                            'file_size' => $fileSize,
                            'height' => $height,
                            'width' => $width,
                        ]);

                        $this->storePostUsage($post, $gallery);
                    });

                    $imported++;
                    $this->line("Post {$post->id}: imported {$featuredImage} to gallery/{$year}/{$month}.");
                }
            });

        $this->info("Done: processed {$processed} posts, imported {$imported}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}|null
     */
    private function parseFeaturedImageUrl(string $featuredImage): ?array
    {
        $path = parse_url($featuredImage, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalizedPath = $path;
        if (Str::startsWith($normalizedPath, '/storage/')) {
            $normalizedPath = substr($normalizedPath, strlen('/storage'));
        }

        if (! preg_match('#^/wp-content/uploads/(\d{4})/(\d{2})/(.+)$#', $normalizedPath, $matches)) {
            return null;
        }

        $year = $matches[1];
        $month = $matches[2];
        $filename = basename($matches[3]);

        return [
            $year,
            $month,
            $filename,
            ltrim($normalizedPath, '/'),
        ];
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

    private function storePostUsage(Post $post, Gallery $gallery): void
    {
        $galleryUsage = $gallery->usages()->create([
            'usage_type' => 'posts',
            'usage_id' => $post->id,
            'alt_text' => self::normalizeNullableText($post->photo_alt_text),
            'photo_credit' => self::normalizeNullableText($post->photo_credit),
            'caption' => self::normalizeNullableText($post->photo_description),
            'description' => null,
            'title' => null,
            'is_primary' => true,
        ]);

        GalleryUsage::query()
            ->where('usage_type', 'posts')
            ->where('usage_id', $post->id)
            ->where('id', '!=', $galleryUsage->id)
            ->update(['is_primary' => false]);
    }
}
