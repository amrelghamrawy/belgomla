<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Orders\Actions\ApplyCollectedAmount;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * BR-R04: cash paid back to the customer because a return left the order collected beyond its net total. A step of
 * Orders' RecordReturn (through the RefundsPayments contract, ReturnRefunds): it joins that transaction, with the
 * order locked and its `returned_total` already raised. The `refund` row is tied to the return, the order's
 * collected amount and status follow (ApplyCollectedAmount) and `collection.refunded` is audited (BR-L).
 */
final readonly class RecordRefund
{
    public function __construct(
        private CollectedAmountQuery $collected,
        private ApplyCollectedAmount $apply,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, int $orderReturnId, string $amount, ?int $actorId = null, ?string $note = null): CashCollection
    {
        return DB::transaction(function () use ($order, $orderReturnId, $amount, $actorId, $note): CashCollection {
            $refund = CashCollection::query()->create([
                'order_id' => $order->id,
                'kind' => CollectionKind::Refund,
                'order_return_id' => $orderReturnId,
                'amount' => $amount,
                'method' => CollectionMethod::Cash,
                'collected_at' => now(),
                'recorded_by' => $actorId,
                'note' => $note,
            ]);

            $this->apply->handle($order, $this->collected->forOrder($order->id));

            $this->audit->log('collection.refunded', $refund, null, [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'order_return_id' => $orderReturnId,
                'amount' => $refund->amount,
                'method' => $refund->method->value,
            ]);

            return $refund->setRelation('order', $order);
        });
    }
}
