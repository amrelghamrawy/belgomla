<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\TenantOrderCounter;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BR-O09: the current tenant's next order number, 1, 2, 3… with no gaps between committed orders. The counter row
 * is created on first use and locked `FOR UPDATE` until the caller's transaction ends, so two checkouts of the
 * same tenant queue here; tenants never wait on each other. Joins the caller's transaction (checkout).
 */
final readonly class OrderNumbers
{
    public function __construct(private TenantContext $tenants) {}

    public function next(): int
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Order numbers are taken inside the checkout transaction.');
        }

        TenantOrderCounter::query()->insertOrIgnore([
            'tenant_id' => $this->tenants->id(),
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = TenantOrderCounter::query()->lockForUpdate()->sole();
        $number = $counter->next_number;

        $counter->next_number = $number + 1;
        $counter->save();

        return $number;
    }
}
