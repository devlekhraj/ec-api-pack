<?php

declare(strict_types=1);

namespace EcApi\Domains\Gallery;

use EcApi\Models\Gallery;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class GalleryUploadService
{
    protected ImageManager $imageManager;

    public function __construct(?ImageManager $imageManager = null)
    {
        $this->imageManager = $imageManager ?? new ImageManager(new Driver());
    }

    public function uploadStandard(UploadedFile $file, ?int $createdById = null): Gallery
    {
        return $this->withFallback(fn (string $disk): Gallery => $this->uploadStandardToDisk($file, $createdById, $disk));
    }

    public function importStandardAtPath(string $sourcePath, string $filepath, ?int $createdById = null): Gallery
    {
        return $this->withFallback(fn (string $disk): Gallery => $this->importStandardToDisk($sourcePath, $filepath, $createdById, $disk));
    }

    public function uploadSocial(UploadedFile $file, ?int $createdById = null): Gallery
    {
        return $this->withFallback(fn (string $disk): Gallery => $this->uploadSocialToDisk($file, $createdById, $disk));
    }

    protected function uploadStandardToDisk(UploadedFile $file, ?int $createdById, string $diskName): Gallery
    {
        $disk = Storage::disk($diskName);
        $directory = now()->format('Y/m/d');
        $filename = $this->generateUniqueFilename($file, $directory, $disk);
        $filepath = "uploads/{$directory}/{$filename}";
        $written = [];

        try {
            $this->putFileAs($disk, 'uploads/'.$directory, $file, $filename);
            $written[] = $filepath;
            $image = $this->imageManager->decodePath($file->getRealPath() ?: $file->getPathname());
            $this->generateVariants($image, 'uploads/'.$directory, $filename, $disk, $written);

            return Gallery::create([
                'filename' => $filename,
                'filepath' => $filepath,
                'storage_disk' => $diskName,
                'mime_type' => (string) $file->getMimeType(),
                'file_size' => (int) $file->getSize(),
                'width' => (int) $image->width(),
                'height' => (int) $image->height(),
                'created_by_id' => $createdById,
            ]);
        } catch (Throwable $exception) {
            $this->deleteFiles($disk, $written);
            throw $exception;
        }
    }

    protected function uploadSocialToDisk(UploadedFile $file, ?int $createdById, string $diskName): Gallery
    {
        $disk = Storage::disk($diskName);
        $directory = now()->format('Y/m/d');
        $filename = $this->generateUniqueFilename($file, $directory, $disk);
        $filepath = "uploads/{$directory}/{$filename}";
        $written = [];
        $image = $this->imageManager->decodePath($file->getRealPath() ?: $file->getPathname());

        if ($image->width() < 1200 || $image->height() < 630) {
            throw new \InvalidArgumentException('Social image must be at least 1200x630 pixels.');
        }

        try {
            $fileSize = (int) $file->getSize();
            if ($image->width() === 1200 && $image->height() === 630) {
                $this->putFileAs($disk, 'uploads/'.$directory, $file, $filename);
            } else {
                $extension = strtolower($file->getClientOriginalExtension());
                $encoded = (string) (clone $image)->cover(1200, 630)->encodeUsingFileExtension($extension);
                $this->put($disk, $filepath, $encoded);
                $fileSize = strlen($encoded);
            }
            $written[] = $filepath;

            return Gallery::create([
                'filename' => $filename,
                'filepath' => $filepath,
                'storage_disk' => $diskName,
                'mime_type' => (string) $file->getMimeType(),
                'file_size' => $fileSize,
                'width' => 1200,
                'height' => 630,
                'created_by_id' => $createdById,
            ]);
        } catch (Throwable $exception) {
            $this->deleteFiles($disk, $written);
            throw $exception;
        }
    }

    protected function importStandardToDisk(string $sourcePath, string $filepath, ?int $createdById, string $diskName): Gallery
    {
        $disk = Storage::disk($diskName);
        $filepath = trim(str_replace('\\', '/', $filepath), '/');
        $filepath = Str::startsWith($filepath, 'uploads/') ? $filepath : 'uploads/'.$filepath;
        $directory = dirname($filepath);
        $filename = basename($filepath);
        $written = [];

        try {
            $contents = file_get_contents($sourcePath);
            if ($contents === false) {
                throw new \RuntimeException("Unable to read source image [{$sourcePath}].");
            }
            $this->put($disk, $filepath, $contents);
            $written[] = $filepath;
            $image = $this->imageManager->decodePath($sourcePath);
            $this->generateMissingVariants($image, $directory, $filename, $disk, $written);

            return Gallery::updateOrCreate(
                ['filepath' => $filepath],
                [
                    'filename' => $filename,
                    'storage_disk' => $diskName,
                    'mime_type' => @mime_content_type($sourcePath) ?: null,
                    'file_size' => filesize($sourcePath) ?: strlen($contents),
                    'width' => (int) $image->width(),
                    'height' => (int) $image->height(),
                    'created_by_id' => $createdById,
                ],
            );
        } catch (Throwable $exception) {
            $this->deleteFiles($disk, $written);
            throw $exception;
        }
    }

    protected function withFallback(callable $operation): Gallery
    {
        $primary = app()->environment('local') ? 'public' : (string) config('filesystems.image_primary_disk', 'r2');
        $fallback = (string) config('filesystems.image_fallback_disk', 'public');

        try {
            return $operation($primary);
        } catch (Throwable $exception) {
            if ($primary === $fallback) {
                throw $exception;
            }

            Log::warning('Primary image storage failed; using local fallback.', [
                'primary_disk' => $primary,
                'fallback_disk' => $fallback,
                'error' => $exception->getMessage(),
            ]);

            return $operation($fallback);
        }
    }

    protected function generateVariants($image, string $directory, string $filename, Filesystem $disk, array &$written): void
    {
        $sourceWidth = (int) $image->width();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $variantMap = [Gallery::SIZE_SM => Gallery::WIDTH_SM, Gallery::SIZE_MD => Gallery::WIDTH_MD, Gallery::SIZE_LG => Gallery::WIDTH_LG, Gallery::SIZE_XL => Gallery::WIDTH_XL];

        foreach ($variantMap as $size => $targetWidth) {
            if ($sourceWidth < $targetWidth) {
                continue;
            }
            $path = "{$directory}/{$basename}-{$size}.{$extension}";
            $this->put($disk, $path, (string) (clone $image)->scale(width: $targetWidth)->encodeUsingFileExtension($extension));
            $written[] = $path;
        }
    }

    protected function generateMissingVariants($image, string $directory, string $filename, Filesystem $disk, array &$written): void
    {
        $sourceWidth = (int) $image->width();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $variantMap = [Gallery::SIZE_SM => Gallery::WIDTH_SM, Gallery::SIZE_MD => Gallery::WIDTH_MD, Gallery::SIZE_LG => Gallery::WIDTH_LG, Gallery::SIZE_XL => Gallery::WIDTH_XL];

        foreach ($variantMap as $size => $targetWidth) {
            if ($sourceWidth < $targetWidth) {
                continue;
            }
            $path = ($directory === '.' ? '' : $directory.'/')."{$basename}-{$size}.{$extension}";
            if ($disk->exists($path)) {
                continue;
            }
            $this->put($disk, $path, (string) (clone $image)->scale(width: $targetWidth)->encodeUsingFileExtension($extension));
            $written[] = $path;
        }
    }

    protected function generateUniqueFilename(UploadedFile $file, string $directory, Filesystem $disk): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'image';
        $filename = "{$base}.{$extension}";
        $counter = 2;

        while ($disk->exists("uploads/{$directory}/{$filename}")) {
            $filename = "{$base}-{$counter}.{$extension}";
            $counter++;
        }

        return $filename;
    }

    protected function putFileAs(Filesystem $disk, string $directory, UploadedFile $file, string $filename): void
    {
        if ($disk->putFileAs($directory, $file, $filename) === false) {
            throw new \RuntimeException("Unable to write file [{$directory}/{$filename}].");
        }
    }

    protected function put(Filesystem $disk, string $path, string $contents): void
    {
        if ($disk->put($path, $contents) === false) {
            throw new \RuntimeException("Unable to write file [{$path}].");
        }
    }

    protected function deleteFiles(Filesystem $disk, array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            try {
                $disk->delete($path);
            } catch (Throwable) {
                // Cleanup must not hide the original upload failure.
            }
        }
    }
}
