<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Contracts\RefundsPayments;
use App\Modules\Orders\Exceptions\ReturnAlreadyVoided;
use App\Modules\Orders\Exceptions\ReturnHasRefund;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/returns/{id}/void (BR-R06): a wrong return is voided with a reason, never deleted — the mirror
 * of RecordReturn. Owns its transaction: the order row is locked first, then the return, then the SKUs. A second
 * void is `409 already_voided`; a live cash refund must be voided first (`409 return_has_refund`, like D7); goods
 * sold or reserved since cannot leave the stock again (`insufficient_stock`, D76). The net total and collection
 * status follow, the history gets a note, and `order.return_voided` is audited.
 */
final readonly class VoidReturn
{
    public function __construct(
        private StockLedger $ledger,
        private RefundsPayments $refunds,
        private ApplyCollectedAmount $collected,
        private RecordStatusChange $history,
        private AuditLogger $audit,
    ) {}

    public function handle(OrderReturn $return, string $reason, ?int $actorId = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $reason, $actorId): OrderReturn {
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($return->voided_at !== null) {
                throw ReturnAlreadyVoided::at($return->voided_at);
            }

            if ($this->refunds->hasLiveRefund($return->id)) {
                throw ReturnHasRefund::amount($return->refund_amount);
            }

            $this->unrestock($order, $return, $actorId);

            $return->forceFill(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => $actorId])->save();
            $order->forceFill(['returned_total' => Money::subtract($order->returned_total, $return->total)])->save();
            $this->collected->handle($order, $order->collected_amount);

            $this->history->handle($order, $order->status, $actorId === null ? ActorType::System : ActorType::TenantUser, $actorId, "إلغاء مرتجع #{$return->number}");
            $this->audit->log('order.return_voided', $return, ['voided_at' => null], [
                'voided_at' => $return->voided_at?->toIso8601String(),
                'void_reason' => $reason,
                'order_number' => $order->number,
                'number' => $return->number,
                'total' => $return->total,
            ]);

            return $return->setRelation('order', $order);
        });
    }

    private function unrestock(Order $order, OrderReturn $return, ?int $actorId): void
    {
        $items = $return->items()->with('orderItem.sku')->get();
        $this->ledger->lock(array_values($items->map(fn (OrderReturnItem $item): int => $item->orderItem->sku_id)->all()));

        foreach ($items as $item) {
            $this->ledger->unrestock($item->orderItem->sku, $item->quantity_base, $order->id, $actorId);
        }
    }
}
