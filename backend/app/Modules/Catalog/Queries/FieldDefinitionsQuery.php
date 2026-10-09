<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\ProductFieldDefinition;
use Illuminate\Database\Eloquent\Collection;

/** Dashboard list of custom fields with their options, archived ones included (BR-P08). */
final readonly class FieldDefinitionsQuery
{
    /**
     * @return Collection<int, ProductFieldDefinition>
     */
    public function get(): Collection
    {
        return ProductFieldDefinition::query()
            ->with('options')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
