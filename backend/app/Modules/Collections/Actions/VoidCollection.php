<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Collections\Exceptions\AlreadyVoided;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Orders\Actions\ApplyCollectedAmount;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/collections/{id}/void (BR-C02): a wrong collection is voided with a reason, never deleted.
 * Owns its transaction: the order row is locked first (the same order as RecordCollection, so the two never
 * deadlock), then the collection; a second void is `409 already_voided`. The order's collected amount and status
 * are recomputed without it (BR-C03), which is what lets a fully voided order be cancelled again (BR-O03), and
 * `collection.voided` is audited (BR-L).
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

            $collection->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $actorId,
            ])->save();

            $this->apply->handle($order, $this->collected->forOrder($order->id));

            $this->audit->log('collection.voided', $collection, ['voided_at' => null], [
                'voided_at' => $collection->voided_at?->toIso8601String(),
                'void_reason' => $reason,
                'order_number' => $order->number,
                'amount' => $collection->amount,
            ]);

            return $collection->setRelation('order', $order);
        });
    }
}
