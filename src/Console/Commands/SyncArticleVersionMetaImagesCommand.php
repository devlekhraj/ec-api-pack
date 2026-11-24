<?php

declare(strict_types=1);

namespace EcApi\Console\Commands;

use EcApi\Domains\Gallery\GalleryUploadService;
use EcApi\Models\ArticleVersion;
use EcApi\Models\Gallery;
use EcApi\Models\GalleryUsage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SyncArticleVersionMetaImagesCommand extends Command
{
    protected $signature = 'articles:sync-meta-images
        {--dry-run : Preview changes without copying files or writing database rows}
        {--limit= : Limit the number of article versions processed}
        {--memory=512M : Memory limit to use while processing images}';

    protected $description = 'Sync article version cover/featured image meta URLs from legacy wp-content files into galleries and gallery_usages.';

    private const SOURCE_PREFIX = 'https://everestchronicle.com/storage/wp-content/';

    private const GALLERY_SOURCE_ROOT = 'files/gallery';

    private const MAX_POST_ORIGINAL_LEGACY_ID = 377;

    /**
     * @var array<string,list<string>>|null
     */
    private ?array $galleryFileIndex = null;

    public function __construct(
        private readonly GalleryUploadService $galleryUploadService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $memoryLimit = trim((string) $this->option('memory'));

        if ($memoryLimit !== '') {
            ini_set('memory_limit', $memoryLimit);
        }

        $processed = 0;
        $synced = 0;
        $skipped = 0;
        $missing = 0;

        $query = ArticleVersion::query()
            ->select(['id', 'meta'])
            ->whereNotNull('meta')
            ->orderBy('id');

        foreach ($query->lazyById(25) as $version) {
            if ($limit !== null && $limit > 0 && ($processed + $skipped + $missing) >= $limit) {
                break;
            }

            $candidate = $this->imageCandidate($version);

            if ($candidate === null) {
                $skipped++;

                continue;
            }

            $processed++;

            $sourcePath = public_path($candidate['source_relative_path']);

            if (! File::exists($sourcePath)) {
                $missing++;
                $this->warn("ArticleVersion {$version->id}: missing source file {$candidate['source_relative_path']}.");

                continue;
            }

            if ($dryRun) {
                $synced++;
                $destination = isset($candidate['destination_relative_path'])
                    ? "to upload/{$candidate['destination_relative_path']} preserving gallery path"
                    : 'via standard gallery upload';

                $this->line(
                    "ArticleVersion {$version->id}: would sync {$candidate['source_key']} " .
                    "from {$candidate['source_relative_path']} {$destination}."
                );

                continue;
            }

            DB::transaction(function () use ($version, $candidate, $sourcePath): void {
                $gallery = $this->galleryForCandidate($candidate, $sourcePath);

                $usage = GalleryUsage::query()->updateOrCreate(
                    [
                        'usage_type' => 'article_versions',
                        'usage_id' => $version->id,
                        'collection' => GalleryUsage::COLLECTION_BANNER,
                    ],
                    [
                        'gallery_id' => $gallery->id,
                        'is_primary' => true,
                        'seq_no' => 0,
                        'alt_text' => $this->normalizeNullableText($candidate['meta']['photo_alt_text'] ?? null),
                        'caption' => $this->normalizeNullableText($candidate['meta']['photo_description'] ?? null),
                        'photo_credit' => $this->normalizeNullableText($candidate['meta']['photo_credit'] ?? null),
                        'custom_properties' => [
                            'source' => "article_versions.meta.{$candidate['source_key']}.url",
                            'source_url' => $candidate['url'],
                            'source_path' => $sourcePath,
                            'source_type' => $candidate['source_type'] ?? null,
                            'destination_path' => $candidate['destination_relative_path'] ?? null,
                            'legacy_post_id' => $candidate['legacy_post_id'] ?? null,
                        ],
                    ],
                );

                GalleryUsage::query()
                    ->where('usage_type', 'article_versions')
                    ->where('usage_id', $version->id)
                    ->where('collection', GalleryUsage::COLLECTION_BANNER)
                    ->whereKeyNot($usage->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            });

            $synced++;
            $this->line("ArticleVersion {$version->id}: synced {$candidate['source_key']}.");

            gc_collect_cycles();
        }

        $mode = $dryRun ? 'Dry run' : 'Done';
        $this->info("{$mode}: processed {$processed}, synced {$synced}, skipped {$skipped}, missing {$missing}.");

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     source_key:string,
     *     url:string,
     *     meta:array<string,mixed>,
     *     filename:string,
     *     source_relative_path:string,
     *     source_type?:string,
     *     destination_relative_path?:string,
     *     legacy_post_id?:int|null
     * }|null
     */
    private function imageCandidate(ArticleVersion $version): ?array
    {
        $meta = $version->meta ?? [];
        $missingCandidate = null;

        foreach (['cover_image', 'featured_image'] as $key) {
            $imageMeta = data_get($meta, $key);

            if (! is_array($imageMeta)) {
                continue;
            }

            $url = trim((string) ($imageMeta['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            if (! Str::startsWith($url, self::SOURCE_PREFIX)) {
                if ($key === 'cover_image') {
                    $galleryCandidate = $this->gallerySourceCandidate($key, $imageMeta, $url);

                    if ($galleryCandidate !== null) {
                        if (File::exists(public_path($galleryCandidate['source_relative_path']))) {
                            return $galleryCandidate;
                        }

                        $missingCandidate ??= $galleryCandidate;
                    }
                }

                $filenameCandidate = $this->filenameOnlyCandidate($meta, $key, $imageMeta, $url);

                if ($filenameCandidate !== null) {
                    if (File::exists(public_path($filenameCandidate['source_relative_path']))) {
                        return $filenameCandidate;
                    }

                    $missingCandidate ??= $filenameCandidate;
                }

                continue;
            }

            $path = parse_url($url, PHP_URL_PATH);

            if (! is_string($path) || $path === '') {
                continue;
            }

            $normalizedPath = preg_replace('#^/storage/#', '', $path);

            if (! is_string($normalizedPath)) {
                continue;
            }

            if (! preg_match('#^wp-content/uploads/(\d{4})/(\d{2})/(.+)$#', $normalizedPath, $matches)) {
                continue;
            }

            $filename = basename($matches[3]);

            $candidate = [
                'source_key' => $key,
                'url' => $url,
                'meta' => $imageMeta,
                'filename' => $filename,
                'source_relative_path' => 'files/' . $normalizedPath,
                'legacy_post_id' => $this->legacyPostId($meta),
            ];

            if (File::exists(public_path($candidate['source_relative_path']))) {
                return $candidate;
            }

            $missingCandidate ??= $candidate;
        }

        return $missingCandidate;
    }

    /**
     * @param  array<string,mixed>  $imageMeta
     * @return array{
     *     source_key:string,
     *     url:string,
     *     meta:array<string,mixed>,
     *     filename:string,
     *     source_relative_path:string,
     *     source_type:string,
     *     destination_relative_path:string,
     *     legacy_post_id?:int|null
     * }|null
     */
    private function gallerySourceCandidate(string $key, array $imageMeta, string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $rawPath = is_string($path) && $path !== '' ? $path : $url;
        $normalizedPath = ltrim(str_replace('\\', '/', $rawPath), '/');
        $normalizedPath = preg_replace('#^(public/)?(files/)?gallery/#', '', $normalizedPath);

        if (! is_string($normalizedPath) || $normalizedPath === '') {
            return null;
        }

        $normalizedPath = $this->safeRelativePath($normalizedPath);

        if ($normalizedPath === null) {
            return null;
        }

        $relativePath = str_contains($normalizedPath, '/')
            ? $normalizedPath
            : $this->galleryRelativePathForFilename($normalizedPath);

        if ($relativePath === null) {
            return null;
        }

        return [
            'source_key' => $key,
            'url' => $url,
            'meta' => $imageMeta,
            'filename' => basename($relativePath),
            'source_relative_path' => self::GALLERY_SOURCE_ROOT . '/' . $relativePath,
            'source_type' => 'legacy_gallery',
            'destination_relative_path' => $relativePath,
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @param  array<string,mixed>  $imageMeta
     * @return array{
     *     source_key:string,
     *     url:string,
     *     meta:array<string,mixed>,
     *     filename:string,
     *     source_relative_path:string,
     *     legacy_post_id:int
     * }|null
     */
    private function filenameOnlyCandidate(array $meta, string $key, array $imageMeta, string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);

        if ($path !== null && $path !== false && $path !== $url) {
            return null;
        }

        $filename = basename($url);

        if ($filename === '' || $filename !== $url) {
            return null;
        }

        $legacyPostId = $this->legacyPostId($meta);

        if ($legacyPostId === null || $legacyPostId > self::MAX_POST_ORIGINAL_LEGACY_ID) {
            return null;
        }

        return [
            'source_key' => $key,
            'url' => $url,
            'meta' => $imageMeta,
            'filename' => $filename,
            'source_relative_path' => "posts/{$legacyPostId}/original/{$filename}",
            'legacy_post_id' => $legacyPostId,
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function legacyPostId(array $meta): ?int
    {
        $legacyPostId = $meta['legacy_post_id'] ?? null;

        return is_numeric($legacyPostId) ? (int) $legacyPostId : null;
    }

    private function safeRelativePath(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        return implode('/', $segments);
    }

    private function galleryRelativePathForFilename(string $filename): ?string
    {
        $matches = $this->galleryFileIndex()[basename($filename)] ?? [];

        sort($matches);

        return $matches[0] ?? null;
    }

    /**
     * @return array<string,list<string>>
     */
    private function galleryFileIndex(): array
    {
        if ($this->galleryFileIndex !== null) {
            return $this->galleryFileIndex;
        }

        $this->galleryFileIndex = [];
        $root = public_path(self::GALLERY_SOURCE_ROOT);

        if (! File::isDirectory($root)) {
            return $this->galleryFileIndex;
        }

        foreach (File::allFiles($root) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $this->galleryFileIndex[$file->getFilename()][] = $relativePath;
        }

        return $this->galleryFileIndex;
    }

    /**
     * @param  array{
     *     source_key:string,
     *     url:string,
     *     meta:array<string,mixed>,
     *     filename:string,
     *     source_relative_path:string,
     *     source_type?:string,
     *     destination_relative_path?:string,
     *     legacy_post_id?:int|null
     * }  $candidate
     */
    private function galleryForCandidate(array $candidate, string $sourcePath): Gallery
    {
        if (isset($candidate['destination_relative_path'])) {
            return $this->galleryFromPreservedDestination($candidate, $sourcePath);
        }

        $existingUsage = GalleryUsage::query()
            ->where('custom_properties->source_url', $candidate['url'])
            ->with('gallery')
            ->first();

        if ($existingUsage?->gallery) {
            return $existingUsage->gallery;
        }

        $existingDestinationGallery = $this->galleryFromExistingDestination($candidate);

        if ($existingDestinationGallery) {
            return $existingDestinationGallery;
        }

        $uploadedFile = new UploadedFile(
            $sourcePath,
            $candidate['filename'],
            @mime_content_type($sourcePath) ?: null,
            null,
            true,
        );

        return $this->galleryUploadService->uploadStandard($uploadedFile);
    }

    /**
     * @param  array{
     *     source_key:string,
     *     url:string,
     *     meta:array<string,mixed>,
     *     filename:string,
     *     source_relative_path:string,
     *     source_type:string,
     *     destination_relative_path:string,
     *     legacy_post_id?:int|null
     * }  $candidate
     */
    private function galleryFromPreservedDestination(array $candidate, string $sourcePath): Gallery
    {
        $createdById = Auth::id();

        return $this->galleryUploadService->importStandardAtPath(
            $sourcePath,
            $candidate['destination_relative_path'],
            $createdById === null ? null : (int) $createdById,
        );
    }

    /**
     * Reuse an already-imported destination file instead of reprocessing the
     * image or letting the upload service create a collision filename.
     *
     * @param  array{
     *     source_key:string,
     *     url:string,
     *     meta:array<string,mixed>,
     *     filename:string,
     *     source_relative_path:string,
     *     legacy_post_id?:int|null
     * }  $candidate
     */
    private function galleryFromExistingDestination(array $candidate): ?Gallery
    {
        $directory = now()->format('Y/m/d');
        $extension = strtolower(pathinfo($candidate['filename'], PATHINFO_EXTENSION));
        $baseName = pathinfo($candidate['filename'], PATHINFO_FILENAME);
        $slugBase = Str::slug($baseName) ?: 'image';
        $filename = "{$slugBase}.{$extension}";
        $relativePath = "{$directory}/{$filename}";

        $gallery = Gallery::query()
            ->where('filepath', $relativePath)
            ->first();

        if ($gallery) {
            return $gallery;
        }

        $disk = Storage::disk((string) config('filesystems.default', 'local'));

        if (! $disk->exists($relativePath)) {
            return null;
        }

        $absolutePath = $disk->path($relativePath);
        $imageSize = @getimagesize($absolutePath);

        return Gallery::query()->create([
            'filename' => $filename,
            'filepath' => $relativePath,
            'mime_type' => @mime_content_type($absolutePath) ?: null,
            'file_size' => File::size($absolutePath),
            'height' => is_array($imageSize) && isset($imageSize[1]) ? (int) $imageSize[1] : null,
            'width' => is_array($imageSize) && isset($imageSize[0]) ? (int) $imageSize[0] : null,
        ]);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || strtolower($normalized) === 'null') {
            return null;
        }

        return $normalized;
    }
}
