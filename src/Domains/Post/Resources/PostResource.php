<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Resources;

use App\Support\Formatters\ByteSizeFormatter;
use App\Support\Formatters\FileDimensionFormatter;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray($request): array
    {
        $bannerImage = null;
        $categoryName = null;

        if ($this->relationLoaded('bannerImage')) {
            $bannerImage = $this->bannerImage;
        }
        if ($this->relationLoaded('categories')) {
            $firstCategory = $this->categories->first();
            if ($firstCategory) {
                $categoryName = $firstCategory->name;
                $extraCount = $this->categories->count() - 1;
                if ($extraCount > 0) {
                    $categoryName .= ' +' . $extraCount;
                }
            }
        }

        if (in_array($request->route()?->getName(), ['admin.blogs.show', 'article.detail'], true)) {
            return $this->showResponse($bannerImage, $categoryName);
        }

        return $this->listResponse($bannerImage, $categoryName);
    }

    private function listResponse($bannerImage, ?string $categoryName): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title ?? $this->name,
            'slug' => $this->slug,
            'file_counts' => $this->files()->count(),
            'status' => (bool) $this->is_published,
            'published_at' => $this->published_on,
            'category_name' => $categoryName,
            'thumb' => $bannerImage?->gallery?->url,
        ];
    }

    private function showResponse($bannerImage, ?string $categoryName): array
    {
        $data = $this->resource->toArray();
        $files = [];

        if ($this->relationLoaded('files')) {
            $files = $this->files->map(static function ($file) {
                $meta = $file->pivot?->meta;
                if (is_string($meta)) {
                    $decoded = json_decode($meta, true);
                    $meta = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
                }
                if (! is_array($meta)) {
                    $meta = [];
                }
                $meta = [
                    'is_default' => in_array($meta['is_default'] ?? false, [true, 1, '1', 'true'], true),
                ];

                $fileSize = ByteSizeFormatter::format($file->file_size ?? null);
                $fileDimension = FileDimensionFormatter::format($file->width ?? null, $file->height ?? null);

                return [
                    'id' => $file->pivot?->id,
                    'url' => $file->url,
                    'alt_text' => $file->pivot?->alt_text,
                    'meta' => $meta,
                    'size_info' => "{$fileSize} | {$fileDimension}",
                ];
            })->values()->all();
        }

        $data['thumb'] = $bannerImage?->gallery?->url;
        $data['status'] = (bool) ($data['status'] ?? $this->status);
        $data['published_at'] = $this->publish_date;
        $data['category_name'] = $categoryName;
        $data['banner_image'] = $bannerImage?->toArray();
        $data['files'] = $files;
        $data['photo_credit'] = $this->photo_credit;
        $data['photo_description'] = $this->photo_description;
        $data['alt_text'] = $bannerImage?->alt_text;

        return $data;
    }
}
