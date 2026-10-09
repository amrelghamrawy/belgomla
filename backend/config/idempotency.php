<?php

declare(strict_types=1);

return [

    /*
    | `Idempotency-Key` on checkout and on dashboard order transitions (08-api-contract.md §0, D55): the first
    | successful answer to a key is replayed for 24 hours. Kept in the cache store below (null = the default
    | store, Redis outside tests) together with a short lock while the first request runs.
    */
    'store' => env('IDEMPOTENCY_STORE'),

    'ttl_seconds' => 86_400,

    'lock_seconds' => 30,

];
