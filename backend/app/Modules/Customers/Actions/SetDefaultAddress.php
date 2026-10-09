<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU03: makes an address the customer's default, unsetting the previous one first so the partial unique
 * index (one active default per customer) never sees two. Joins the caller's transaction.
 */
final readonly class SetDefaultAddress
{
    public function handle(CustomerAddress $address): CustomerAddress
    {
        return DB::transaction(function () use ($address): CustomerAddress {
            CustomerAddress::query()
                ->where('customer_id', $address->customer_id)
                ->whereKeyNot($address->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $address->forceFill(['is_default' => true])->save();

            return $address;
        });
    }
}
