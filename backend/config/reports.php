<?php

declare(strict_types=1);

return [

    /*
    | D94: the advanced reports (V2 Task 16) are cached per tenant and filter set for this many seconds, with a TTL only
    | (nothing busts the cache on a write); the answer carries `generated_at` so the page can show «آخر تحديث».
    */
    'cache_seconds' => 600,

    /*
    | V2 Task 16: a customer with no order for more than this many Cairo days is dormant (customer patterns), and a SKU
    | with stock but no sale for more than this many days is dead stock (inventory analytics). Both can be changed per
    | request (`dormant_days`, `dead_days`).
    */
    'dormant_days' => 45,

    'dead_stock_days' => 60,

    /*
    | The page size of the paginated reports when none is asked: the first page is the «top 20».
    */
    'per_page' => 20,

];
