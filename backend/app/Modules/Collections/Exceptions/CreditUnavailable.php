<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use App\Support\ApiException;

/**
 * BR-C07: a customer credit that cannot be used or voided as asked: voided already (`409 credit_voided`), or partly
 * applied, so it cannot be voided before those collections are (`409 credit_applied`, `details.applied`).
 */
final class CreditUnavailable extends ApiException
{
    public static function voided(): self
    {
        return new self(409, 'credit_voided', 'تم إلغاء هذا الرصيد');
    }

    public static function applied(string $applied): self
    {
        return new self(409, 'credit_applied', "تم استخدام {$applied} من هذا الرصيد، ألغِ التحصيلات المخصومة منه أولًا", ['applied' => $applied]);
    }
}
