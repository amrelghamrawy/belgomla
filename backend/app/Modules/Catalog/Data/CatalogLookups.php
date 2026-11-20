<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\Unit;

/**
 * The tenant's catalog structure, read once per import or export by CatalogRowMapper::lookups() so a file of
 * thousands of rows costs a handful of queries (V2 Task 06). A read model, not request input.
 *
 *  - `categories`: every category by id, `paths`: id => "كتب خارجية > أولى إعدادي", `byPath`: the folded path =>
 *    the ids that carry it (two siblings with the same name make a path ambiguous),
 *  - `units`: folded name => unit (BR-U01: names are unique per tenant),
 *  - `fields`: the active custom field definitions with their options, in form order, keyed by key,
 *  - `tierColumns`: how many tier column pairs the file has (at least 5, more when a SKU has more tiers).
 */
final readonly class CatalogLookups
{
    /**
     * @param  array<int, Category>  $categories
     * @param  array<int, string>  $paths
     * @param  array<string, list<int>>  $byPath
     * @param  array<string, Unit>  $units
     * @param  array<string, ProductFieldDefinition>  $fields
     */
    public function __construct(
        public array $categories,
        public array $paths,
        public array $byPath,
        public array $units,
        public array $fields,
        public int $tierColumns = 5,
    ) {}
}
