<?php

declare(strict_types=1);

namespace EcApi\Observers;

use EcApi\Models\Short;
use EcApi\Support\NextRevalidator;

final class ShortObserver
{
    public function saved(Short $short): void
    {
        // Revalidate if it is currently published or was previously published
        if ($short->status === Short::STATUS_PUBLISHED || $short->getOriginal('status') === Short::STATUS_PUBLISHED) {
            NextRevalidator::revalidate(['homepage', 'shorts']);
        }
    }

    public function deleted(Short $short): void
    {
        if ($short->status === Short::STATUS_PUBLISHED || $short->getOriginal('status') === Short::STATUS_PUBLISHED) {
            NextRevalidator::revalidate(['homepage', 'shorts']);
        }
    }

    public function restored(Short $short): void
    {
        if ($short->status === Short::STATUS_PUBLISHED) {
            NextRevalidator::revalidate(['homepage', 'shorts']);
        }
    }
}
