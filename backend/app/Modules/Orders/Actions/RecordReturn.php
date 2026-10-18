<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Contracts\RefundsPayments;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\RefundRequired;
use App\Modules\Orders\Exceptions\ReturnExceedsDelivered;
use App\Modules\Orders\Exceptions\ReturnNotAllowed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Orders\Services\ReturnableQuantities;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * POST /api/dashboard/orders/{number}/returns (BR-R01..R05, V2 Task 01): the merchant records goods a customer
 * brought back. Owns its transaction: the order row is locked first (like collections, so the two never deadlock)
 * and re-read, then the SKUs in sku_id order. Only a delivered order (BR-R01), never more than returnable per line
 * (BR-R02); the goods go back into stock (BR-R03), priced at the line's snapshot price (D74); the order's net total
 * drops, and what was collected beyond it is refunded in cash once the merchant confirms (BR-R04). The history gets
 * a note (no status change, no merchant notification) and `order.returned` is audited.
 */
final readonly class RecordReturn
{
    public function __construct(
        private ReturnableQuantities $returnable,
        private StockLedger $ledger,
        private RefundsPayments $refunds,
        private ApplyCollectedAmount $collected,
        private RecordStatusChange $history,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, ReturnData $data, ?int $actorId = null): OrderReturn
    {
        return DB::transaction(function () use ($order, $data, $actorId): OrderReturn {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Delivered) {
                throw ReturnNotAllowed::status($order->status);
            }

            $items = $this->assertReturnable($order, $data->lines);
            $return = $this->createReturn($order, $data, $items, $actorId);

            $order->forceFill(['returned_total' => Money::add($order->returned_total, $return->total)])->save();
            $this->settle($order, $return, $data->refund, $actorId);

            $this->history->handle($order, $order->status, $actorId === null ? ActorType::System : ActorType::TenantUser, $actorId, "مرتجع #{$return->number}");
            $this->audit->log('order.returned', $return, null, [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'number' => $return->number,
                'total' => $return->total,
                'refund_amount' => $return->refund_amount,
                'reason' => $return->reason,
                'items' => $return->items->map(fn (OrderReturnItem $item): array => ['order_item_id' => $item->order_item_id, 'quantity' => $item->quantity])->values()->all(),
            ]);

            return $return->setRelation('order', $order);
        });
    }

    /**
     * BR-R02: every line names a line of this order and stays within what is still returnable.
     *
     * @param  list<ReturnLineData>  $lines
     * @return Collection<int, OrderItem> the order lines returned, keyed by id
     */
    private function assertReturnable(Order $order, array $lines): Collection
    {
        $returnable = $this->returnable->for($order);
        $problems = [];

        foreach ($lines as $line) {
            $left = $returnable[$line->orderItemId] ?? 0;

            if ($line->quantity > $left) {
                $problems[] = ['order_item_id' => $line->orderItemId, 'requested' => $line->quantity, 'returnable' => $left];
            }
        }

        if ($problems !== []) {
            throw ReturnExceedsDelivered::items($problems);
        }

        return OrderItem::query()->with('sku')->whereKey(array_map(fn (ReturnLineData $line): int => $line->orderItemId, $lines))->get()->keyBy('id');
    }

    /**
     * BR-R03, D74: the return and its lines at the snapshot unit price, each line's base units back into stock.
     *
     * @param  Collection<int, OrderItem>  $items
     */
    private function createReturn(Order $order, ReturnData $data, Collection $items, ?int $actorId): OrderReturn
    {
        $last = OrderReturn::query()->where('order_id', $order->id)->max('number');
        $number = (is_numeric($last) ? (int) $last : 0) + 1;
        $lines = [];
        $total = '0.00';

        foreach ($data->lines as $line) {
            $item = $items->get($line->orderItemId) ?? throw new RuntimeException("Order item {$line->orderItemId} vanished.");
            $lines[] = [$item, $line->quantity, Money::times($item->unit_price, $line->quantity)];
            $total = Money::add($total, Money::times($item->unit_price, $line->quantity));
        }

        $return = OrderReturn::query()->create([
            'order_id' => $order->id,
            'number' => $number,
            'total' => $total,
            'reason' => $data->reason,
            'returned_at' => $data->returnedAt ?? now(),
            'recorded_by' => $actorId,
        ]);

        $this->ledger->lock(array_values($items->map(fn (OrderItem $item): int => $item->sku_id)->all()));

        foreach ($lines as [$item, $quantity, $lineTotal]) {
            OrderReturnItem::query()->create([
                'order_return_id' => $return->id,
                'order_item_id' => $item->id,
                'quantity' => $quantity,
                'quantity_base' => $quantity * $item->conversion_factor,
                'unit_price' => $item->unit_price,
                'line_total' => $lineTotal,
            ]);
            $this->ledger->restock($item->sku, $quantity * $item->conversion_factor, $order->id, $actorId);
        }

        return $return->load('items.orderItem');
    }

    /** BR-R04: pay back what was collected beyond the new net total (confirmed by the merchant), then re-derive the status. */
    private function settle(Order $order, OrderReturn $return, bool $refund, ?int $actorId): void
    {
        $excess = Money::subtract($order->collected_amount, $order->netTotal());

        if (Money::compare($excess, '0.00') > 0) {
            if (! $refund) {
                throw RefundRequired::amount($excess);
            }

            $return->forceFill(['refund_amount' => $excess])->save();
            $this->refunds->refund($order, $return->id, $excess, $actorId);
            $order->refresh();
        }

        $this->collected->handle($order, $order->collected_amount);
    }
}
