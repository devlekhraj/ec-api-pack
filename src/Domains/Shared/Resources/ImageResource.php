<?php

declare(strict_types=1);

namespace EcApi\Domains\Shared\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \EcApi\Models\Gallery */
final class ImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'url' => $this->url,
        ];
    }
}
