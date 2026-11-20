<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Support\ApiException;

/**
 * BR-O11: a merchant places orders only for active customers. A blocked one is `409 customer_blocked`; one who has
 * not activated their account yet (invited, pending approval) is `409 customer_not_active`; both with `details.status`.
 */
final class CustomerCannotOrder extends ApiException
{
    public static function because(CustomerStatus $status): self
    {
        if ($status === CustomerStatus::Blocked) {
            return new self(409, 'customer_blocked', 'هذا العميل محظور، ألغِ الحظر أولًا لتسجيل طلب له', ['status' => $status->value]);
        }

        return new self(409, 'customer_not_active', "لا يمكن تسجيل طلب لعميل حالته «{$status->label()}»", ['status' => $status->value]);
    }
}
