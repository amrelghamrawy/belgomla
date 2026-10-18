<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturnItem;

/**
 * BR-R02: how many selling units of each line can still come back = delivered − returned by live returns. A
 * delivered order delivered every line in full (BR-O06), so delivered is the line's quantity; a voided return
 * gives its quantity back.
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

        foreach (OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get(['id', 'quantity']) as $item) {
            $returnable[$item->id] = $item->quantity - ($returned[$item->id] ?? 0);
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
