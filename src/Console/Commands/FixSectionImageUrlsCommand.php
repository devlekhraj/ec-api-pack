<?php

declare(strict_types=1);

namespace EcApi\Console\Commands;

use EcApi\Domains\Gallery\GalleryUploadService;
use EcApi\Models\Gallery;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Repairs legacy image URLs embedded in content_sections.text (rich-text HTML).
 *
 * Two URL patterns are handled:
 *
 *   1. /storage/wp-content/uploads/YYYY/MM/{filename}
 *      → search in public/files/wp-content/uploads/YYYY/MM/
 *
 *   2. https://everestchronicle.com/storage/posts/{id}/content_image/{filename}
 *      → search in public/posts/{id}/content_image/  (and subdirs)
 *
 *   3. https://everestchronicle.com/image/{filename}
 *      → search in public/files/**  (recursive)
 *
 * Decision tree for each URL found:
 *   A. Filename already exists in storage/app/public/uploads/**
 *      → regenerate URL from Gallery::getUrl() and replace inline. No file move.
 *   B. Not in our storage, but found in public/posts/** or public/files/**
 *      → Import file using the standard gallery upload service
 *      → WordPress (wp-content) URLs keep their legacy YYYY/MM upload folder:
 *        uploads/2022/08/photo-1024x768.jpg
 *          → uploads/2022/08/photo.jpg (+ photo-sm/md/lg/xl.jpg variants)
 *      → Create Gallery record
 *      → Replace URL in section text
 *   C. Not found anywhere
 *      → Leave payload untouched, add to not-found report
 */
final class FixSectionImageUrlsCommand extends Command
{
    protected $signature = 'sections:fix-image-urls
        {--dry-run   : Preview changes without writing to the database or moving files}
        {--section=  : Process a single section by ID}
        {--memory=512M : Memory limit to use while processing images}
        {--verbose-matches : Print every URL processed, not just changes}';

    protected $description = 'Repair legacy everestchronicle.com image URLs embedded in content_sections.text';

    // ── URL patterns to detect ────────────────────────────────────────────────

    private const PATTERN_POSTS = '#https?://(?:www\.)?everestchronicle\.com/storage/posts/(\d+)/[^"\'>\s]+#i';
    private const PATTERN_IMAGE = '#https?://(?:www\.)?everestchronicle\.com/image/([^"\'>\s]+)#i';
    private const PATTERN_WP_CONTENT = '#(?:https?://(?:www\.)?everestchronicle\.com)?/storage/wp-content/uploads/(\d{4})/(\d{2})/[^"\'>\s]+#i';

    // ── Runtime state ─────────────────────────────────────────────────────────

    /** @var list<array{section_id:int,url:string,searched:string}> */
    private array $notFound = [];

    private int $countFixed    = 0;
    private int $countMigrated = 0;
    private int $countSkipped  = 0;

    /** Flat index: filename (lowercase) → absolute path on disk. Built lazily. */
    private ?array $uploadsIndex = null;
    private ?array $postsIndex   = null;
    private ?array $filesIndex   = null;

    // ─────────────────────────────────────────────────────────────────────────

    public function __construct(
        private readonly GalleryUploadService $galleryUploadService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun        = (bool) $this->option('dry-run');
        $singleSection = $this->option('section') !== null
            ? (int) $this->option('section')
            : null;
        $memoryLimit = trim((string) $this->option('memory'));

        if ($memoryLimit !== '') {
            ini_set('memory_limit', $memoryLimit);
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Section Image URL Repair</>');
        $this->line('  <fg=gray>Scanning content_sections.text for legacy image URLs...</>');
        $this->line('');

        if ($dryRun) {
            $this->warn('  DRY RUN — no files will be moved, no DB rows will change.');
            $this->line('');
        }

        $query = DB::table('content_sections')
            ->select(['id', 'text'])
            ->where(function ($query): void {
                $query->whereRaw('text LIKE ?', ['%everestchronicle.com%'])
                    ->orWhereRaw('text LIKE ?', ['%/storage/wp-content/uploads/%']);
            })
            ->orderBy('id');

        if ($singleSection !== null) {
            $query->where('id', $singleSection);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('  No sections with legacy image URLs found.');
            return self::SUCCESS;
        }

        $this->line("  Found <options=bold>{$total}</> section(s) containing legacy URLs.");
        $this->line('');

        foreach ($query->lazyById(25) as $section) {
            $this->processSection($section, $dryRun);
            gc_collect_cycles();
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Core section processing
    // ─────────────────────────────────────────────────────────────────────────

    private function processSection(object $section, bool $dryRun): void
    {
        $text    = (string) $section->text;
        $updated = $text;

        // Collect all legacy URLs in this section (both patterns)
        $allMatches = $this->extractLegacyUrls($text);

        if (empty($allMatches)) {
            return;
        }

        foreach ($allMatches as ['url' => $url, 'pattern' => $pattern, 'postId' => $postId]) {
            $filename = basename(parse_url($url, PHP_URL_PATH) ?? $url);

            $this->line("  Section <options=bold>#{$section->id}</> [{$pattern}]");
            $this->line("    <fg=gray>URL:</> {$url}");

            $result = $this->resolveUrl($section->id, $url, $filename, $pattern, $postId, $dryRun);

            if ($result !== null) {
                $updated = str_replace($url, $result, $updated);
                $this->line("    <fg=green>✓ → {$result}</>");
            } else {
                $this->line("    <fg=red>✗ Not found</>");
            }

            $this->line('');
        }

        // Only persist if text actually changed
        if (!$dryRun && $updated !== $text) {
            DB::table('content_sections')
                ->where('id', $section->id)
                ->update(['text' => $updated]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // URL resolution — the core 3-case decision tree
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveUrl(
        int $sectionId,
        string $url,
        string $filename,
        string $pattern,
        ?int $postId,
        bool $dryRun,
    ): ?string {
        $filenameLower = strtolower($filename);

        // ── Case A: Already in our storage/app/public/uploads/** ────────────
        $uploadsAbsPath = $this->findInUploads($filenameLower);

        if ($uploadsAbsPath !== null) {
            $gallery = Gallery::where('filename', $filename)->first()
                ?? Gallery::where('filename', strtolower($filename))->first();

            if ($gallery !== null) {
                $this->line("    <fg=gray>Found in our gallery disk (gallery #{$gallery->id})</>");
                $this->countFixed++;
                return $gallery->getUrl();
            }

            // File exists on disk but no gallery record — create one
            if (!$dryRun) {
                $gallery = $this->createGalleryRecord($uploadsAbsPath, $filename, $this->relativeFromUploadsRoot($uploadsAbsPath));
            }

            $this->line("    <fg=yellow>~ Found in uploads (no gallery record — creating)</>  {$uploadsAbsPath}");
            $this->countFixed++;

            return $gallery?->getUrl() ?? $this->buildUploadUrl($uploadsAbsPath);
        }

        // ── Case B: Not in our storage — search legacy directories ──────────
        $legacyAbsPath = $this->findInLegacyDirs($pattern === 'wp-content' ? $url : $filename, $pattern, $postId);

        if ($legacyAbsPath !== null) {
            // WordPress uploads carry their own YYYY/MM folder in the URL, so keep it.
            // The posts/ and image/ patterns have no legacy date and keep using the
            // standard upload flow (today's folder).
            $destination = $pattern === 'wp-content'
                ? $this->legacyDestinationPath($url, $legacyAbsPath)
                : null;

            $this->line("    <fg=yellow>→ Found in legacy dir: {$legacyAbsPath}</>");

            if ($destination !== null) {
                $this->line("    <fg=yellow>  Importing to legacy path: uploads/{$destination}</>");
            } else {
                $this->line("    <fg=yellow>  Importing through standard gallery upload</>  ");
            }

            if ($dryRun) {
                $this->countMigrated++;
                return $this->publicStorageUrl($destination ?? ('dry-run/' . $filename));
            }

            $newUrl = $this->migrateFile($legacyAbsPath, $filename, $destination);

            if ($newUrl !== null) {
                $this->countMigrated++;
                return $newUrl;
            }
        }

        // ── Case C: Not found anywhere ───────────────────────────────────────
        $this->notFound[] = [
            'section_id' => $sectionId,
            'url'        => $url,
            'searched'   => 'uploads/**, public/posts/**, public/files/**',
        ];

        $this->countSkipped++;
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // File searching
    // ─────────────────────────────────────────────────────────────────────────

    private function findInUploads(string $filenameLower): ?string
    {
        if ($this->uploadsIndex === null) {
            $disk = (string) config('filesystems.default', 'public');
            $this->uploadsIndex = $this->buildFileIndex(
                Storage::disk($disk)->path(''),
            );
        }

        return $this->uploadsIndex[$filenameLower] ?? null;
    }

    private function findInLegacyDirs(string $filename, string $pattern, ?int $postId): ?string
    {
        $filenameLower = strtolower($filename);

        if ($pattern === 'wp-content') {
            $path = parse_url($filename, PHP_URL_PATH);
            $relativePath = is_string($path) && $path !== '' ? $path : $filename;
            $relativePath = ltrim($relativePath, '/');
            $relativePath = preg_replace('#^storage/#', '', $relativePath);

            if (is_string($relativePath)) {
                $exact = public_path('files/' . $relativePath);

                if (File::exists($exact)) {
                    return $exact;
                }

                $originalRelativePath = $this->withoutWordPressSizeSuffix($relativePath);

                if ($originalRelativePath !== $relativePath) {
                    $originalExact = public_path('files/' . $originalRelativePath);

                    if (File::exists($originalExact)) {
                        return $originalExact;
                    }

                    $scaledExact = public_path('files/' . $this->withScaledSuffix($originalRelativePath));

                    if (File::exists($scaledExact)) {
                        return $scaledExact;
                    }
                }

                $sameFolderMatch = $this->findClosestInSameFolder(public_path('files/' . dirname($relativePath)), basename($relativePath));

                if ($sameFolderMatch !== null) {
                    return $sameFolderMatch;
                }
            }

            if ($this->filesIndex === null) {
                $this->filesIndex = $this->buildFileIndex(public_path('files'));
            }

            $basename = basename(is_string($relativePath) ? $relativePath : $filename);
            $basenameLower = strtolower($basename);
            $originalBasenameLower = strtolower($this->withoutWordPressSizeSuffix($basename));
            $scaledBasenameLower = strtolower($this->withScaledSuffix($this->withoutWordPressSizeSuffix($basename)));

            return $this->filesIndex[$basenameLower]
                ?? $this->filesIndex[$originalBasenameLower]
                ?? $this->filesIndex[$scaledBasenameLower]
                ?? null;
        }

        if ($pattern === 'posts') {
            // Try exact path first when we have a post ID
            if ($postId !== null) {
                $exact = public_path("posts/{$postId}/content_image/{$filename}");
                if (File::exists($exact)) {
                    return $exact;
                }
            }

            // Full recursive search across all public/posts/**
            if ($this->postsIndex === null) {
                $this->postsIndex = $this->buildFileIndex(public_path('posts'));
            }
            return $this->postsIndex[$filenameLower] ?? null;
        }

        // pattern === 'image' — search public/files/**
        if ($this->filesIndex === null) {
            $this->filesIndex = $this->buildFileIndex(public_path('files'));
        }
        return $this->filesIndex[$filenameLower] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // File migration
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Import a legacy file into our storage.
     *
     * When $destinationRelativePath is given (WordPress uploads) the file is
     * written to that exact legacy path and only missing size variants are
     * generated. Otherwise the standard gallery upload flow is used, which
     * places the file in today's Y/m/d folder.
     */
    private function migrateFile(
        string $absSourcePath,
        string $filename,
        ?string $destinationRelativePath = null,
    ): ?string {
        try {
            if ($destinationRelativePath !== null) {
                $gallery = $this->galleryUploadService->importStandardAtPath($absSourcePath, $destinationRelativePath);

                return $gallery->getUrl();
            }

            $uploadedFile = new UploadedFile(
                $absSourcePath,
                $filename,
                @mime_content_type($absSourcePath) ?: null,
                null,
                true,
            );

            $gallery = $this->galleryUploadService->uploadStandard($uploadedFile);

            return $gallery->getUrl();
        } catch (\Throwable $exception) {
            $this->line('    <fg=red>✗ Import failed:</> ' . e($exception->getMessage()));

            return null;
        }
    }

    /**
     * Destination path for a legacy WordPress upload, preserving the legacy
     * YYYY/MM folder (and any deeper subfolder) from the URL instead of using
     * today's folder.
     *
     * Example:
     *   /storage/wp-content/uploads/2022/08/photo-1024x768.jpg
     *   → 2022/08/photo.jpg
     *
     * Returns null when the URL does not carry a safe YYYY/MM path, in which
     * case the caller falls back to the standard upload flow.
     */
    private function legacyDestinationPath(string $url, string $absSourcePath): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $url;

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        $relativePath = (string) preg_replace('#^storage/#i', '', $relativePath);
        $relativePath = (string) preg_replace('#^wp-content/uploads/#i', '', $relativePath);

        $segments = array_values(array_filter(
            explode('/', $relativePath),
            static fn(string $segment): bool => $segment !== '',
        ));

        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        $filename  = $this->canonicalLegacyFilename((string) array_pop($segments));
        $directory = implode('/', $segments);

        // Keep the path legacy-faithful: it must start with the URL's YYYY/MM.
        if (! preg_match('#^\d{4}/\d{2}(?:/|$)#', $directory)) {
            return null;
        }

        $destination = $directory === '' ? $filename : "{$directory}/{$filename}";

        return $this->uniqueImportPath($destination, $absSourcePath);
    }

    /**
     * Normalize a WordPress filename to its canonical base name by stripping
     * the generated size/scale suffixes, so all variants share one base.
     *
     *   photo-1024x768.jpg  → photo.jpg
     *   photo-scaled.jpg    → photo.jpg
     *   photo-1024x768.jpeg → photo.jpeg
     */
    private function canonicalLegacyFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename  = pathinfo($filename, PATHINFO_FILENAME);
        $previous  = null;

        while ($previous !== $basename) {
            $previous = $basename;
            $basename = (string) preg_replace('/(?:-\d+x\d+|-scaled)$/i', '', $basename);
        }

        if ($basename === '') {
            $basename = pathinfo($filename, PATHINFO_FILENAME);
        }

        return $extension === '' ? $basename : $basename . '.' . strtolower($extension);
    }

    /**
     * Resolve the import path, reusing an existing file when it holds the same
     * bytes and otherwise appending -2, -3, ... so a different legacy image
     * never silently inherits (or overwrites) another image's file.
     */
    private function uniqueImportPath(string $relativePath, string $absSourcePath): ?string
    {
        $disk = Storage::disk((string) config('filesystems.default', 'public'));

        if (! $disk->exists($relativePath)) {
            return $relativePath;
        }

        if ($this->filesAreIdentical($absSourcePath, $disk->path($relativePath))) {
            return $relativePath;
        }

        $directory = dirname($relativePath);
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $basename  = pathinfo($relativePath, PATHINFO_FILENAME);
        $suffix    = $extension === '' ? '' : ".{$extension}";

        for ($counter = 2; $counter <= 999; $counter++) {
            $candidate = $directory === '.' || $directory === ''
                ? "{$basename}-{$counter}{$suffix}"
                : "{$directory}/{$basename}-{$counter}{$suffix}";

            if (! $disk->exists($candidate)) {
                return $candidate;
            }

            if ($this->filesAreIdentical($absSourcePath, $disk->path($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    private function filesAreIdentical(string $absSourcePath, string $absDestinationPath): bool
    {
        $sourceSize      = @filesize($absSourcePath);
        $destinationSize = @filesize($absDestinationPath);

        if ($sourceSize === false || $destinationSize === false || $sourceSize !== $destinationSize) {
            return false;
        }

        return @md5_file($absSourcePath) === @md5_file($absDestinationPath);
    }

    private function createGalleryRecord(string $absPath, string $filename, string $relativePath): Gallery
    {
        $mimeType = (string) (@mime_content_type($absPath) ?: 'image/jpeg');
        $fileSize = (int) @filesize($absPath);

        [$width, $height] = @getimagesize($absPath) ?: [0, 0];

        return Gallery::firstOrCreate(
            ['filename' => $filename],
            [
                'filepath'  => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'width'     => (int) $width,
                'height'    => (int) $height,
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a flat filename → absolute path index for a directory tree.
     * When there are duplicates (same filename in multiple subdirs), the first
     * hit wins (order is filesystem-dependent but consistent per run).
     *
     * @return array<string, string>  key: lowercase filename, value: absolute path
     */
    private function buildFileIndex(string $rootDir): array
    {
        $index = [];

        if (!is_dir($rootDir)) {
            return $index;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $lower = strtolower($file->getFilename());

            if (!isset($index[$lower])) {
                $index[$lower] = $file->getRealPath();
            }
        }

        return $index;
    }

    /**
     * @return list<array{url:string, pattern:string, postId:int|null}>
     */
    private function extractLegacyUrls(string $html): array
    {
        $found = [];
        $seen  = [];

        // Pattern 1: /storage/wp-content/uploads/YYYY/MM/...
        if (preg_match_all(self::PATTERN_WP_CONTENT, $html, $m)) {
            foreach ($m[0] as $url) {
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $found[]    = ['url' => $url, 'pattern' => 'wp-content', 'postId' => null];
            }
        }

        // Pattern 2: /storage/posts/{id}/...
        if (preg_match_all(self::PATTERN_POSTS, $html, $m)) {
            foreach ($m[0] as $i => $url) {
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url]  = true;
                $found[]     = ['url' => $url, 'pattern' => 'posts', 'postId' => (int) $m[1][$i]];
            }
        }

        // Pattern 3: /image/{filename}
        if (preg_match_all(self::PATTERN_IMAGE, $html, $m)) {
            foreach ($m[0] as $url) {
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $found[]    = ['url' => $url, 'pattern' => 'image', 'postId' => null];
            }
        }

        return $found;
    }

    private function relativeFromUploadsRoot(string $absPath): string
    {
        $disk = (string) config('filesystems.default', 'public');
        $root = rtrim(Storage::disk($disk)->path(''), DIRECTORY_SEPARATOR);
        return ltrim(Str::after($absPath, $root), DIRECTORY_SEPARATOR);
    }

    private function buildUploadUrl(string $absPath): string
    {
        $disk    = (string) config('filesystems.default', 'public');
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);
        $root    = rtrim((string) $storage->path(''), '/');
        $rel     = ltrim(Str::after($absPath, $root), '/');
        return $storage->url($rel);
    }

    private function publicStorageUrl(string $relativePath): string
    {
        $disk = (string) config('filesystems.default', 'public');

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        return $storage->url($relativePath);
    }

    private function withoutWordPressSizeSuffix(string $path): string
    {
        return preg_replace('/-\d+x\d+(?=\.[^.\/]+$)/', '', $path) ?? $path;
    }

    private function withScaledSuffix(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return $path;
        }

        $directory = dirname($path);
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $filename = "{$basename}-scaled.{$extension}";

        return $directory === '.' ? $filename : "{$directory}/{$filename}";
    }

    private function findClosestInSameFolder(string $directory, string $filename): ?string
    {
        if (! File::isDirectory($directory)) {
            return null;
        }

        $needle = $this->normalizedMatchKey($this->withoutWordPressSizeSuffix($filename));
        $matches = [];

        foreach (File::files($directory) as $file) {
            $candidate = $file->getFilename();

            if (strcasecmp(pathinfo($candidate, PATHINFO_EXTENSION), pathinfo($filename, PATHINFO_EXTENSION)) !== 0) {
                continue;
            }

            $candidateKey = $this->normalizedMatchKey($candidate);

            if ($candidateKey === '' || $needle === '') {
                continue;
            }

            if (str_starts_with($candidateKey, $needle) || str_starts_with($needle, $candidateKey)) {
                $matches[] = $file->getPathname();
            }
        }

        sort($matches);

        return $matches[0] ?? null;
    }

    private function normalizedMatchKey(string $filename): string
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $basename = preg_replace('/-\d+x\d+$/', '', $basename) ?? $basename;
        $basename = preg_replace('/-scaled$/i', '', $basename) ?? $basename;

        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $basename));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Summary output
    // ─────────────────────────────────────────────────────────────────────────

    private function printSummary(): void
    {
        $this->line('  ' . str_repeat('─', 60));
        $this->line('  <options=bold>SUMMARY</>');
        $this->line('  ' . str_repeat('─', 60));
        $this->line("  <fg=green>✓ Fixed (already in our storage):</>   {$this->countFixed}");
        $this->line("  <fg=yellow>→ Migrated from legacy dirs:</>         {$this->countMigrated}");
        $this->line("  <fg=red>✗ Not found:</>                         " . count($this->notFound));
        $this->line('');

        if (empty($this->notFound)) {
            $this->info('  All image URLs resolved successfully.');
            return;
        }

        $this->line('  ' . str_repeat('─', 60));
        $this->line('  <fg=red;options=bold>NOT FOUND — manual action required</>');
        $this->line('  ' . str_repeat('─', 60));

        $headers = ['Section ID', 'URL'];
        $rows    = array_map(
            fn($r) => [$r['section_id'], $r['url']],
            $this->notFound,
        );

        $this->table($headers, $rows);
        $this->line('');
        $this->warn('  These URLs remain unchanged in the database. Upload the missing files manually,');
        $this->warn('  then re-run this command to patch them.');
    }
}
