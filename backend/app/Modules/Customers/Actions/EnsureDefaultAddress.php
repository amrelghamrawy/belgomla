<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\CustomerAddress;

/**
 * BR-CU03: a customer with active addresses always has a default, so checkout can preselect one. When none
 * is the default (first address added, default archived), the oldest active one becomes it. Joins the
 * caller's transaction.
 */
final readonly class EnsureDefaultAddress
{
    public function __construct(private SetDefaultAddress $setDefault) {}

    public function handle(int $customerId): void
    {
        $active = CustomerAddress::query()->where('customer_id', $customerId)->where('status', AddressStatus::Active);

        if ((clone $active)->where('is_default', true)->exists()) {
            return;
        }

        $oldest = $active->orderBy('id')->first();

        if ($oldest instanceof CustomerAddress) {
            $this->setDefault->handle($oldest);
        }
    }
}
