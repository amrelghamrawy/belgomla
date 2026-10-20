<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Catalog\Contracts\PreorderDemand;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * BR-O14, D88: what customers pre-ordered and did not receive yet as normal orders — per product, the open quantity (the
 * unconverted remainder of every open pre-order line, selling units), how many customers and pre-orders hold it — for the
 * merchant's purchase planning (`GET /api/dashboard/products/{id}/preorder-demand`), the product status answer and the
 * storefront's remaining quantity under the cap (Catalog's PreorderDemand, bound in OrdersServiceProvider), and per SKU for
 * the cap check of a new pre-order (PlaceOrder, under the product lock).
 */
final readonly class PreorderDemandQuery implements PreorderDemand
{
    /**
     * @return array{quantity: int, customers: int, preorders: int}
     */
    public function of(Product $product): array
    {
        $row = $this->openLines()
            ->where('order_items.product_id', $product->id)
            ->toBase()
            ->selectRaw('coalesce(sum(order_items.quantity - order_items.converted_quantity), 0) as quantity')
            ->selectRaw('count(distinct orders.customer_id) as customers')
            ->selectRaw('count(distinct orders.id) as preorders')
            ->first();

        return [
            'quantity' => $this->int($row->quantity ?? 0),
            'customers' => $this->int($row->customers ?? 0),
            'preorders' => $this->int($row->preorders ?? 0),
        ];
    }

    /**
     * The open pre-ordered selling units of each SKU given (SKUs nobody pre-ordered are left out).
     *
     * @param  list<int>  $skuIds
     * @return array<int, int> sku_id => selling units
     */
    public function openQuantities(array $skuIds): array
    {
        $rows = $this->openLines()
            ->whereIn('order_items.sku_id', $skuIds)
            ->toBase()
            ->groupBy('order_items.sku_id')
            ->selectRaw('order_items.sku_id, sum(order_items.quantity - order_items.converted_quantity) as quantity')
            ->get();

        $open = [];

        foreach ($rows as $row) {
            $open[$this->int($row->sku_id)] = $this->int($row->quantity);
        }

        return $open;
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
