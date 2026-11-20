<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Exceptions\CannotCollect;
use App\Modules\Collections\Exceptions\CreditUnavailable;
use App\Modules\Collections\Exceptions\ExceedsBalance;
use App\Modules\Collections\Exceptions\ExceedsCredit;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Orders\Actions\ApplyCollectedAmount;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\ApiException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/orders/{number}/apply-credit (BR-C07): part of a customer credit pays an order of the same
 * customer, as a `payment` collection with method `credit`. Owns its transaction: the order row is locked first (as in
 * every collection write), then the credit, so two uses of one credit queue up. Never more than the credit's
 * remaining (`422 exceeds_credit`) nor the order's remaining balance (`422 exceeds_balance`); another customer's credit
 * is not found; a voided credit is `409 credit_voided`. Audited `customer_credit.applied`.
 */
final readonly class ApplyCustomerCredit
{
    public function __construct(
        private CollectedAmountQuery $collected,
        private ApplyCollectedAmount $apply,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, CustomerCredit $credit, string $amount, ?int $actorId = null): CashCollection
    {
        return DB::transaction(function () use ($order, $credit, $amount, $actorId): CashCollection {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $credit = CustomerCredit::query()->lockForUpdate()->findOrFail($credit->id);

            $this->assertUsable($order, $credit, $amount);

            $collection = CashCollection::query()->create([
                'order_id' => $order->id,
                'kind' => CollectionKind::Payment,
                'customer_credit_id' => $credit->id,
                'amount' => $amount,
                'method' => CollectionMethod::Credit,
                'collected_at' => now(),
                'recorded_by' => $actorId,
            ]);

            $before = $credit->remaining;
            $credit->forceFill(['remaining' => Money::subtract($before, $amount)])->save();
            $this->apply->handle($order, $this->collected->forOrder($order->id));

            $this->audit->log('customer_credit.applied', $credit, ['remaining' => $before], [
                'remaining' => $credit->remaining,
                'order_number' => $order->number,
                'collection_id' => $collection->id,
                'amount' => $collection->amount,
            ]);

            return $collection->setRelation('order', $order);
        });
    }

    private function assertUsable(Order $order, CustomerCredit $credit, string $amount): void
    {
        if ($credit->customer_id !== $order->customer_id) {
            throw ApiException::notFound();
        }

        if ($credit->voided_at !== null) {
            throw CreditUnavailable::voided();
        }

        if ($order->status === OrderStatus::Cancelled) {
            throw CannotCollect::cancelled();
        }

        if ($order->kind === OrderKind::Preorder) {
            throw CannotCollect::preorder();
        }

        if (Money::compare($amount, $credit->remaining) > 0) {
            throw ExceedsCredit::remaining($credit->remaining);
        }

        $remaining = Money::subtract($order->netTotal(), $this->collected->forOrder($order->id));
        $remaining = Money::compare($remaining, '0.00') < 0 ? '0.00' : $remaining;

        if (Money::compare($amount, $remaining) > 0) {
            throw ExceedsBalance::remaining($remaining);
        }
    }
}
