<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Orders\Models\Scopes\Live;

/**
 * BR-R02: how many selling units of each line can still come back = delivered − returned by live returns. A line
 * delivered short counts what went out (`delivered_quantity`, BR-O13), a removed line nothing (BR-O12); a voided
 * return gives its quantity back. A bundle line (BR-P15, D93) comes back through its component lines, so it is not listed.
 */
final readonly class ReturnableQuantities
{
    /**
     * @return array<int, int> order item id => returnable selling units, in line order
     */
    public function for(Order $order): array
    {
        $returned = $this->returned($order);
        $returnable = [];

        foreach (OrderItem::query()->where('order_id', $order->id)->where('is_bundle', false)->tap(new Live)->orderBy('id')->get(['id', 'quantity', 'delivered_quantity']) as $item) {
            $returnable[$item->id] = ($item->delivered_quantity ?? $item->quantity) - ($returned[$item->id] ?? 0);
        }

        return $returnable;
    }

    /**
     * @return array<int, int> order item id => selling units brought back by live returns
     */
    public function returned(Order $order): array
    {
        $rows = OrderReturnItem::query()
            ->toBase()
            ->join('order_returns', 'order_returns.id', '=', 'order_return_items.order_return_id')
            ->where('order_returns.order_id', $order->id)
            ->whereNull('order_returns.voided_at')
            ->groupBy('order_return_items.order_item_id')
            ->select('order_return_items.order_item_id')
            ->selectRaw('sum(order_return_items.quantity) as quantity')
            ->get();

        $returned = [];

        foreach ($rows as $row) {
            /** @var object{order_item_id: int|string, quantity: int|string} $row */
            $returned[(int) $row->order_item_id] = (int) $row->quantity;
        }

        return $returned;
    }
}
