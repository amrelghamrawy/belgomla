<?php

declare(strict_types=1);

namespace App\Modules\Collections\Services;

use App\Modules\Collections\Actions\KeepAsCredit;
use App\Modules\Collections\Actions\RecordRefund;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\Scopes\NotVoided;
use App\Modules\Orders\Contracts\RefundsPayments;
use App\Modules\Orders\Models\Order;

/**
 * Orders' RefundsPayments (BR-R04, BR-R06, BR-C07): records the cash refund of a return (RecordRefund) or keeps it as
 * customer credit (KeepAsCredit), and tells VoidReturn whether that refund is still live, so a return is voided only
 * after its refund (like D7).
 */
final readonly class ReturnRefunds implements RefundsPayments
{
    public function __construct(
        private RecordRefund $record,
        private KeepAsCredit $keepAsCredit,
    ) {}

    public function refund(Order $order, int $orderReturnId, string $amount, ?int $actorId): void
    {
        $this->record->handle($order, $orderReturnId, $amount, $actorId);
    }

    public function keepAsCredit(Order $order, int $orderReturnId, string $amount, ?int $actorId): void
    {
        $this->keepAsCredit->handle($order, $orderReturnId, $amount, $actorId);
    }

    public function hasLiveRefund(int $orderReturnId): bool
    {
        return CashCollection::query()
            ->where('kind', CollectionKind::Refund)
            ->where('order_return_id', $orderReturnId)
            ->tap(new NotVoided)
            ->exists();
    }
}
