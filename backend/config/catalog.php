<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Printable price list (BR-P13)
    |--------------------------------------------------------------------------
    |
    | The price list is not paginated; it returns at most this many products and
    | flags `meta.truncated` when the selection is larger (08-api-contract.md §1).
    |
    */
    'price_list_limit' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Storefront facet counts (Tech §9b)
    |--------------------------------------------------------------------------
    |
    | Seconds a facet count stays cached per tenant and selection. Any product,
    | field, option or category change invalidates all of a tenant's entries.
    |
    */
    'facet_cache_seconds' => 600,

];
