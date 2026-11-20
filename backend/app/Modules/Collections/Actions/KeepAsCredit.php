<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Collections\Queries\CollectedAmountQuery;
use App\Modules\Orders\Actions\ApplyCollectedAmount;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * BR-C07: a return's over-collection kept as customer credit instead of cash. A step of Orders' RecordReturn (through
 * RefundsPayments, ReturnRefunds), inside its transaction with the order locked: a customer credit of the amount, and a
 * `refund` collection with method `credit` pointing at it, so the order's collected amount drops exactly as for a cash
 * refund while no cash moves. Audited `customer_credit.created`.
 */
final readonly class KeepAsCredit
{
    public function __construct(
        private CollectedAmountQuery $collected,
        private ApplyCollectedAmount $apply,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, int $orderReturnId, string $amount, ?int $actorId = null): CustomerCredit
    {
        return DB::transaction(function () use ($order, $orderReturnId, $amount, $actorId): CustomerCredit {
            $credit = CustomerCredit::query()->create([
                'customer_id' => $order->customer_id,
                'source_return_id' => $orderReturnId,
                'amount' => $amount,
                'remaining' => $amount,
                'created_by' => $actorId,
            ]);

            CashCollection::query()->create([
                'order_id' => $order->id,
                'kind' => CollectionKind::Refund,
                'order_return_id' => $orderReturnId,
                'customer_credit_id' => $credit->id,
                'amount' => $amount,
                'method' => CollectionMethod::Credit,
                'collected_at' => now(),
                'recorded_by' => $actorId,
            ]);

            $this->apply->handle($order, $this->collected->forOrder($order->id));

            $this->audit->log('customer_credit.created', $credit, null, [
                'customer_id' => $credit->customer_id,
                'order_number' => $order->number,
                'order_return_id' => $orderReturnId,
                'amount' => $credit->amount,
            ]);

            return $credit;
        });
    }
}
