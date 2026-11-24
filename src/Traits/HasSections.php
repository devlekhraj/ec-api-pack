<?php

declare(strict_types=1);

namespace EcApi\Traits;

use EcApi\Models\ContentSection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasSections
{
    public function sections(): MorphMany
    {
        return $this->morphMany(ContentSection::class, 'sectionable')->orderBy('sort_order');
    }
}
