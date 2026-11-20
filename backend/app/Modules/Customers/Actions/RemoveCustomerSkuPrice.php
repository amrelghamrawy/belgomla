<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSkuPrice;

/**
 * DELETE /api/dashboard/customers/{id}/prices/{sku_unit} (BR-PR13, V2 Task 11): the customer goes back to the normal price of
 * the SKU (base, tier or their group's). No such price is `404`. Audited as `customer.price_removed` with the price it had.
 */
final readonly class RemoveCustomerSkuPrice
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Customer $customer, SkuUnit $unit): void
    {
        $price = CustomerSkuPrice::query()->where('customer_id', $customer->id)->where('sku_unit_id', $unit->id)->first()
            ?? throw CustomerPricingRefused::noPrice();

        $price->delete();

        $this->audit->log('customer.price_removed', $customer, ['sku_id' => $unit->sku_id, 'sku_unit_id' => $unit->id, 'unit_price' => $price->unit_price, 'note' => $price->note]);
    }
}
