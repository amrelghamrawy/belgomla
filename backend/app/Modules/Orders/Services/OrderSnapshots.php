<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Models\Order;

/**
 * What an order keeps of the catalog and the customer at checkout, so later changes never rewrite it:
 * the line snapshot (BR-PR07, Tech §12) and the delivery snapshot (BR-O10).
 */
final readonly class OrderSnapshots
{
    /**
     * The order_items columns of a checked line.
     *
     * @return array<string, int|string|null>
     */
    public function item(CheckoutLine $line): array
    {
        $sku = $line->sku;
        $unit = $line->unit;

        return [
            'sku_id' => $sku->id,
            'sku_unit_id' => $unit->id,
            'product_id' => $sku->product_id,
            'product_name' => $sku->product->name,
            'sku_code' => $sku->code,
            'sku_name' => $sku->name ?? $sku->product->name,
            'unit_name' => $unit->unit->name, // D91: the unit the line is in
            'conversion_factor' => $unit->conversion_factor,
            'quantity' => $line->quantity,
            'quantity_base' => $line->quantityBase(),
            'cover_price' => $line->price->coverPrice,
            'list_unit_price' => $line->price->unitPrice, // no manual discount yet (D85)
            'unit_price' => $line->price->unitPrice,
            'discount_percent' => $line->price->discountPercent,
            'tier_min_quantity' => $line->price->tierMinQuantity,
            'price_source' => $line->price->source->value, // BR-PR14, BR-PR15: base, tier, group, customer or promotion
            'promotion_id' => $line->price->promotion?->promotionId,
            'line_total' => $line->price->lineTotal,
        ];
    }

    /**
     * The orders delivery columns: the customer's name, the address phone or else the customer's, and the address.
     *
     * @return array{delivery_name: string, delivery_phone: string, delivery_address: array<string, string|null>}
     */
    public function delivery(Customer $customer, CustomerAddress $address): array
    {
        return [
            'delivery_name' => $customer->name,
            'delivery_phone' => $address->phone ?? $customer->phone,
            'delivery_address' => [
                'label' => $address->label,
                'governorate' => $address->governorate,
                'city' => $address->city,
                'address_line' => $address->address_line,
                'landmark' => $address->landmark,
            ],
        ];
    }

    /**
     * D88: a normal order converted from a pre-order goes where the pre-order was to go: its delivery snapshot, as the
     * merchant may have edited it (BR-O10), not the customer's address book.
     *
     * @return array{delivery_name: string, delivery_phone: string, delivery_address: array<string, string|null>}
     */
    public function deliveryOf(Order $order): array
    {
        return [
            'delivery_name' => $order->delivery_name,
            'delivery_phone' => $order->delivery_phone,
            'delivery_address' => $order->delivery_address,
        ];
    }
}
