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

    /*
    |--------------------------------------------------------------------------
    | Catalog import / export (V2 Task 06, D83, D95)
    |--------------------------------------------------------------------------
    |
    | export_sync_limit: above this many products an export runs as a queued job
    |   and is downloaded from a temporary signed URL instead of streaming.
    | import_max_kb: the largest file the import preview accepts.
    | import_token_hours: how long an uploaded file stays importable after preview
    |   (and how long an export file stays downloadable).
    | import_preview_errors / import_stored_errors: how many row errors the
    |   preview returns and how many the `imports` row keeps.
    | import_chunk: rows one queued import job applies before it queues the next,
    |   so every job ends well inside the queue's retry_after (90 s).
    |
    */
    'export_sync_limit' => 5000,

    'import_max_kb' => 10240,

    'import_token_hours' => 24,

    'import_preview_errors' => 200,

    'import_stored_errors' => 500,

    'import_chunk' => 200,

];
