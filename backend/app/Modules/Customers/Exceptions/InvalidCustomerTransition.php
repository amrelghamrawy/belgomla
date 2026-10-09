<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Support\ApiException;

/** BR-CU05: a status change the state machine (07-domain-model.md §11) does not allow. */
final class InvalidCustomerTransition extends ApiException
{
    public static function from(CustomerStatus $from, CustomerStatus $to): self
    {
        return new self(
            409,
            'invalid_transition',
            "لا يمكن تغيير حالة العميل من «{$from->label()}» إلى «{$to->label()}»",
            ['from' => $from->value, 'to' => $to->value],
        );
    }

    /** BR-CU05: only a blocked customer can be unblocked. */
    public static function notBlocked(CustomerStatus $status): self
    {
        return new self(409, 'invalid_transition', "العميل غير محظور، حالته «{$status->label()}»", ['from' => $status->value]);
    }

    /** BR-CU08: only a customer who has not activated yet can be (re-)invited. */
    public static function notInvitable(CustomerStatus $status): self
    {
        return new self(409, 'invalid_transition', "لا يمكن دعوة عميل حالته «{$status->label()}»", ['from' => $status->value]);
    }
}
