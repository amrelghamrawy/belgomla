<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Data\CheckoutComponent;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Allocation;
use App\Support\Money;
use RoundingMode;

/**
 * What an order keeps of the catalog and the customer at checkout, so later changes never rewrite it:
 * the line snapshot (BR-PR07, Tech §12) and the delivery snapshot (BR-O10).
 *
 * A bundle line (BR-P15, V2 Task 15, D93) is snapshotted as itself (`is_bundle`) plus one child line per component:
 * the bundle's price per bundle is split over its components in proportion to what each would cost on its own
 * (Allocation: half-up, the remainder on the last), each child's `line_total` is its share × the bundles ordered (so
 * the children add up to the bundle line exactly) and its `unit_price` that share per unit, half-up and never below 0.01,
 * which is what a return of it gives back.
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
     * D93: the order_items columns of a bundle line's children, `$bundlePrice` per bundle (the bundle's unit price, after
     * its manual discount when it has one) split over the components.
     *
     * @return list<array<string, int|string|bool|null>>
     */
    public function components(CheckoutLine $line, ?string $bundlePrice = null): array
    {
        $shares = Allocation::proportional($bundlePrice ?? $line->price->unitPrice, array_map(fn (CheckoutComponent $component): string => $component->weight, $line->components));
        $rows = [];

        foreach ($line->components as $index => $component) {
            $sku = $component->sku;
            $quantity = $line->quantity * $component->perBundle;
            $unitPrice = $this->perUnit($shares[$index] ?? '0.00', $component->perBundle);

            $rows[] = [
                'sku_id' => $sku->id,
                'sku_unit_id' => $component->unit->id,
                'product_id' => $sku->product_id,
                'product_name' => $sku->product->name,
                'sku_code' => $sku->code,
                'sku_name' => $sku->name ?? $sku->product->name,
                'unit_name' => $component->unit->unit->name,
                'conversion_factor' => $component->unit->conversion_factor,
                'quantity' => $quantity,
                'quantity_base' => $quantity * $component->unit->conversion_factor,
                'cover_price' => null,
                'list_unit_price' => $unitPrice,
                'unit_price' => $unitPrice,
                'discount_percent' => null,
                'tier_min_quantity' => null,
                'price_source' => 'base',
                'promotion_id' => null,
                'line_total' => Money::times($shares[$index] ?? '0.00', $line->quantity),
            ];
        }

        return $rows;
    }

    /**
     * D93: the children of a bundle line split its new price per bundle again, in proportion to their current shares
     * (a manual discount on the bundle line, BR-PR08). The rows are filled, not saved.
     *
     * @param  iterable<OrderItem>  $children
     */
    public function reallocate(OrderItem $bundle, iterable $children): void
    {
        $list = [];

        foreach ($children as $child) {
            $list[] = $child;
        }

        $children = $list;

        if ($children === []) {
            return;
        }

        $perBundle = array_map(fn (OrderItem $child): string => bcdiv($child->line_total, (string) max(1, $bundle->quantity), 2), $children);
        $shares = Allocation::proportional($bundle->unit_price, $perBundle);

        foreach ($children as $index => $child) {
            $unitPrice = $this->perUnit($shares[$index] ?? '0.00', intdiv($child->quantity, max(1, $bundle->quantity)));
            $child->forceFill(['list_unit_price' => $unitPrice, 'unit_price' => $unitPrice, 'line_total' => Money::times($shares[$index] ?? '0.00', $bundle->quantity)]);
        }
    }

    /** A component's share of one bundle per unit of it: half-up to the piaster, never below 0.01. */
    private function perUnit(string $share, int $perBundle): string
    {
        $price = bcround(bcdiv($share, (string) max(1, $perBundle), 6), 2, RoundingMode::HalfAwayFromZero);

        return Money::compare($price, '0.01') < 0 ? '0.01' : bcadd($price, '0', 2);
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
