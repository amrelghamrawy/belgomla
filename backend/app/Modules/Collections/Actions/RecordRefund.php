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
use InvalidArgumentException;

/**
 * BR-R04: money paid back to the customer because a return left the order collected beyond its net total, by a
 * recordable method with an optional reference (BR-C10). A step of
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

    public function handle(Order $order, int $orderReturnId, string $amount, ?int $actorId = null, ?string $note = null, CollectionMethod $method = CollectionMethod::Cash, ?string $reference = null): CashCollection
    {
        if (! in_array($method, CollectionMethod::recordable(), true)) {
            throw new InvalidArgumentException("A refund is paid back by a recordable method, not {$method->value}.");
        }

        return DB::transaction(function () use ($order, $orderReturnId, $amount, $actorId, $note, $method, $reference): CashCollection {
            $refund = CashCollection::query()->create([
                'order_id' => $order->id,
                'kind' => CollectionKind::Refund,
                'order_return_id' => $orderReturnId,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
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
                'reference' => $refund->reference,
            ]);

            return $refund->setRelation('order', $order);
        });
    }
}
