<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Exceptions\CreditUnavailable;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Collections\Models\Scopes\NotVoided;

/**
 * POST /api/dashboard/credits/{id}/void (BR-C07): a credit is voided only while none of it was applied. A credit kept
 * from a return is voided through its `credit` refund (VoidCollection voids both under the order and credit locks, so
 * the order's collected amount rises back and the return can be voided after it); a second void is
 * `409 credit_voided`, a partly applied credit `409 credit_applied`.
 */
final readonly class VoidCustomerCredit
{
    public function __construct(private VoidCollection $voidCollection) {}

    public function handle(CustomerCredit $credit, string $reason, ?int $actorId = null): CustomerCredit
    {
        if ($credit->voided_at !== null) {
            throw CreditUnavailable::voided();
        }

        $refund = CashCollection::query()
            ->where('customer_credit_id', $credit->id)
            ->where('kind', CollectionKind::Refund)
            ->tap(new NotVoided)
            ->firstOrFail();

        $this->voidCollection->handle($refund, $reason, $actorId);

        return $credit->refresh();
    }
}
