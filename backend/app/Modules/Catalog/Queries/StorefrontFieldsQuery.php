<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use Illuminate\Database\Eloquent\Collection;

/**
 * The custom fields a customer sees on a product (BR-P08): active, `show_on_storefront`, in the
 * merchant's sort order, keyed by field key, options loaded (archived ones too, because a product may
 * still hold one).
 */
final readonly class StorefrontFieldsQuery
{
    /**
     * @return Collection<string, ProductFieldDefinition>
     */
    public function get(): Collection
    {
        return ProductFieldDefinition::query()
            ->with('options')
            ->where('status', ArchiveStatus::Active)
            ->where('show_on_storefront', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('key');
    }
}
