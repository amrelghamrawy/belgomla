<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Scopes\Open;
use Illuminate\Database\Eloquent\Builder;

/**
 * BR-INV08: the open orders whose lines hold a SKU's reservation, oldest first, for the "cannot go below
 * reserved" message. Bound for Inventory's ReservationHolders contract in OrdersServiceProvider.
 */
final readonly class OrdersHoldingSku implements ReservationHolders
{
    /**
     * @return list<array{number: int, status: string, quantity_base: int}>
     */
    public function ordersHolding(Sku $sku): array
    {
        return array_values(OrderItem::query()
            ->with('order')
            ->where('sku_id', $sku->id)
            ->whereHas('order', fn (Builder $order): Builder => $order->tap(new Open))
            ->orderBy('order_id')
            ->get()
            ->map(fn (OrderItem $item): array => [
                'number' => $item->order->number,
                'status' => $item->order->status->value,
                'quantity_base' => $item->quantity_base,
            ])
            ->all());
    }
}
