<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Exceptions\CannotCollect;
use App\Modules\Collections\Exceptions\ExceedsBalance;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Orders\Actions\ApplyCollectedAmount;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/orders/{number}/collections (BR-C02, BR-C10): the merchant records money collected on an order,
 * by a recordable method with an optional reference. Owns
 * its transaction (a savepoint when TransitionOrder delivers with `collect_full_amount`, BR-C04): the order row is
 * locked and re-read, so two collections cannot both fit the same balance; any status but cancelled is accepted
 * (BR-C04); more than the remaining balance (net total − collected, BR-O08) is `422 exceeds_balance` (BR-C03). The order's collected amount and
 * status follow (ApplyCollectedAmount) and `collection.recorded` is audited (BR-L).
 */
final readonly class RecordCollection
{
    public function __construct(
        private CollectedAmountQuery $collected,
        private ApplyCollectedAmount $apply,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, CollectionData $data, ?int $actorId = null): CashCollection
    {
        return DB::transaction(function () use ($order, $data, $actorId): CashCollection {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === OrderStatus::Cancelled) {
                throw CannotCollect::cancelled();
            }

            if ($order->kind === OrderKind::Preorder) {
                throw CannotCollect::preorder();
            }

            $collected = $this->collected->forOrder($order->id);
            $remaining = Money::subtract($order->netTotal(), $collected);
            $remaining = Money::compare($remaining, '0.00') < 0 ? '0.00' : $remaining;

            if (Money::compare($data->amount, $remaining) > 0) {
                throw ExceedsBalance::remaining($remaining);
            }

            $collection = CashCollection::query()->create([
                'order_id' => $order->id,
                'kind' => CollectionKind::Payment,
                'amount' => $data->amount,
                'method' => $data->method,
                'reference' => $data->reference,
                'collected_at' => $data->collectedAt ?? now(),
                'recorded_by' => $actorId,
                'note' => $data->note,
            ]);

            $this->apply->handle($order, Money::add($collected, $data->amount));

            $this->audit->log('collection.recorded', $collection, null, [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'amount' => $collection->amount,
                'method' => $collection->method->value,
                'reference' => $collection->reference,
                'collected_at' => $collection->collected_at->toIso8601String(),
                'note' => $collection->note,
            ]);

            return $collection->setRelation('order', $order);
        });
    }
}
