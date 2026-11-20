<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Catalog\Contracts\PreorderDemand;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * BR-O14, D88: what customers pre-ordered and did not receive yet as normal orders — per product, the open quantity (the
 * unconverted remainder of every open pre-order line: `quantity` in the units ordered, `quantity_base` in base units, D91), how many customers and pre-orders hold it — for the
 * merchant's purchase planning (`GET /api/dashboard/products/{id}/preorder-demand`), the product status answer and the
 * storefront's remaining quantity under the cap (Catalog's PreorderDemand, bound in OrdersServiceProvider), and per SKU for
 * the cap check of a new pre-order (PlaceOrder, under the product lock).
 */
final readonly class PreorderDemandQuery implements PreorderDemand
{
    /**
     * @return array{quantity: int, quantity_base: int, customers: int, preorders: int}
     */
    public function of(Product $product): array
    {
        $row = $this->openLines()
            ->where('order_items.product_id', $product->id)
            ->toBase()
            ->selectRaw('coalesce(sum(order_items.quantity - order_items.converted_quantity), 0) as quantity')
            ->selectRaw('coalesce(sum((order_items.quantity - order_items.converted_quantity) * order_items.conversion_factor), 0) as quantity_base')
            ->selectRaw('count(distinct orders.customer_id) as customers')
            ->selectRaw('count(distinct orders.id) as preorders')
            ->first();

        return [
            'quantity' => $this->int($row->quantity ?? 0),
            'quantity_base' => $this->int($row->quantity_base ?? 0),
            'customers' => $this->int($row->customers ?? 0),
            'preorders' => $this->int($row->preorders ?? 0),
        ];
    }

    /**
     * The open pre-ordered base units of each SKU given, whatever unit they were pre-ordered in (D91; SKUs nobody
     * pre-ordered are left out).
     *
     * @param  list<int>  $skuIds
     * @return array<int, int> sku_id => base units
     */
    public function openQuantities(array $skuIds): array
    {
        $rows = $this->openLines()
            ->whereIn('order_items.sku_id', $skuIds)
            ->toBase()
            ->groupBy('order_items.sku_id')
            ->selectRaw('order_items.sku_id, sum((order_items.quantity - order_items.converted_quantity) * order_items.conversion_factor) as quantity')
            ->get();

        $open = [];

        foreach ($rows as $row) {
            $open[$this->int($row->sku_id)] = $this->int($row->quantity);
        }

        return $open;
    }

    /**
     * What is left under the product's pre-order cap, in its SKU's default unit (null = no cap): the cap is in the default
     * unit and every open pre-order counts by its base units, whatever unit it was taken in (D91).
     *
     * @param  array{quantity: int, quantity_base: int, customers: int, preorders: int}  $demand  of()
     */
    public function remaining(Product $product, array $demand): ?int
    {
        if ($product->preorder_limit === null) {
            return null;
        }

        $factor = SkuUnit::query()
            ->join('skus', 'skus.id', '=', 'sku_units.sku_id')
            ->where('skus.product_id', $product->id)
            ->where('sku_units.is_default', true)
            ->orderBy('skus.id')
            ->value('sku_units.conversion_factor');
        $factor = max(1, is_numeric($factor) ? (int) $factor : 1);

        return intdiv(max(0, $product->preorder_limit * $factor - $demand['quantity_base']), $factor);
    }

    /**
     * Lines of open pre-orders with something left to convert.
     *
     * @return Builder<OrderItem>
     */
    private function openLines(): Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.kind', OrderKind::Preorder->value)
            ->where('orders.status', OrderStatus::Pending->value)
            ->whereNull('order_items.removed_at')
            ->whereColumn('order_items.converted_quantity', '<', 'order_items.quantity');
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
