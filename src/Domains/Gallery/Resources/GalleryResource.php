<?php

declare(strict_types=1);

namespace EcApi\Domains\Gallery\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \EcApi\Models\Gallery */
final class GalleryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $aspectRatio = null;
        if (is_numeric($this->width) && is_numeric($this->height) && (float) $this->height !== 0.0) {
            $aspectRatio = round((float) $this->width / (float) $this->height, 2);
        }

        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'filepath' => $this->filepath,
            'url' => route('image.view', ['filename' => $this->filename]),
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'height' => $this->height,
            'width' => $this->width,
            'aspect_ratio' => $aspectRatio,
            'usages_count' => (int) ($this->usages_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
