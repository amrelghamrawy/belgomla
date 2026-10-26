<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSkuPrice;

/**
 * PUT /api/dashboard/customers/{id}/prices/{sku} (BR-PR13, V2 Task 11, D89): sets the unit price negotiated with the
 * customer for the SKU, in its selling unit, with an optional note. It may be above the normal price: it is what was
 * negotiated. One per customer and SKU (sent again = replaced). It applies from the customer's next price; orders already
 * placed keep their snapshot (BR-PR07). Audited as `customer.price_set` with the old and new price; sending the same
 * price and note writes nothing.
 */
final readonly class SetCustomerSkuPrice
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Customer $customer, Sku $sku, string $unitPrice, ?string $note): CustomerSkuPrice
    {
        $price = CustomerSkuPrice::query()->firstOrNew(['customer_id' => $customer->id, 'sku_id' => $sku->id]);
        $old = $price->exists ? ['unit_price' => $price->unit_price, 'note' => $price->note] : null;

        $price->fill(['unit_price' => $unitPrice, 'note' => $note]);

        if ($price->exists && ! $price->isDirty()) {
            return $price;
        }

        $price->save();

        $this->audit->log('customer.price_set', $customer, $old === null ? null : ['sku_id' => $sku->id, ...$old], [
            'sku_id' => $sku->id,
            'unit_price' => $price->refresh()->unit_price,
            'note' => $price->note,
        ]);

        return $price;
    }
}
