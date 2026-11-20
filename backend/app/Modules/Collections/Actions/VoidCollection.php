<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Exceptions\AlreadyVoided;
use App\Modules\Collections\Exceptions\CreditUnavailable;
use App\Modules\Collections\Exceptions\PaymentHasRefunds;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Orders\Actions\ApplyCollectedAmount;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/collections/{id}/void (BR-C02): a wrong collection is voided with a reason, never deleted.
 * Owns its transaction: the order row is locked first (the same order as RecordCollection, so the two never
 * deadlock), then the collection, then its customer credit if any; a second void is `409 already_voided`. Payments and
 * refunds void alike; a payment whose void would leave more refunded than collected is `409 void_refund_first`
 * (BR-R04). A credit payment gives its amount back to the credit; a credit refund (a return kept as credit, BR-C07)
 * voids the credit with it, only while none of the credit was applied (`409 credit_applied`). The order's collected
 * amount and status are recomputed without it (BR-C03), which is what lets a fully voided order be cancelled again
 * (BR-O03), and `collection.voided` is audited (BR-L).
 */
final readonly class VoidCollection
{
    public function __construct(
        private CollectedAmountQuery $collected,
        private ApplyCollectedAmount $apply,
        private AuditLogger $audit,
    ) {}

    public function handle(CashCollection $collection, string $reason, ?int $actorId = null): CashCollection
    {
        return DB::transaction(function () use ($collection, $reason, $actorId): CashCollection {
            $order = Order::query()->lockForUpdate()->findOrFail($collection->order_id);
            $collection = CashCollection::query()->lockForUpdate()->findOrFail($collection->id);

            if ($collection->voided_at !== null) {
                throw AlreadyVoided::at($collection->voided_at);
            }

            $collected = $this->collected->forOrder($order->id);
            $after = $collection->kind === CollectionKind::Refund ? Money::add($collected, $collection->amount) : Money::subtract($collected, $collection->amount);

            if (Money::compare($after, '0.00') < 0) {
                throw PaymentHasRefunds::make();
            }

            $collection->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $actorId,
            ])->save();

            if ($collection->customer_credit_id !== null) {
                $this->settleCredit($collection, $reason, $actorId);
            }

            $this->apply->handle($order, $after);

            $this->audit->log('collection.voided', $collection, ['voided_at' => null], [
                'voided_at' => $collection->voided_at?->toIso8601String(),
                'void_reason' => $reason,
                'order_number' => $order->number,
                'amount' => $collection->amount,
            ]);

            return $collection->setRelation('order', $order);
        });
    }

    /** BR-C07: a voided credit payment refills its credit; a voided credit refund takes the unused credit away. */
    private function settleCredit(CashCollection $collection, string $reason, ?int $actorId): void
    {
        $credit = CustomerCredit::query()->lockForUpdate()->findOrFail($collection->customer_credit_id);

        if ($collection->kind === CollectionKind::Payment) {
            $credit->forceFill(['remaining' => Money::add($credit->remaining, $collection->amount)])->save();

            return;
        }

        if (Money::compare($credit->remaining, $credit->amount) !== 0) {
            throw CreditUnavailable::applied(Money::subtract($credit->amount, $credit->remaining));
        }

        $credit->forceFill(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => $actorId])->save();
    }
}
