<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU03: "deleting" an address archives it; order snapshots keep their copy (BR-O10). When it was the
 * default, the oldest remaining address takes over. Joins the caller's transaction.
 */
final readonly class ArchiveAddress
{
    public function __construct(private EnsureDefaultAddress $ensureDefault) {}

    public function handle(CustomerAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $address->forceFill(['status' => AddressStatus::Archived, 'is_default' => false])->save();

            $this->ensureDefault->handle($address->customer_id);
        });
    }
}
