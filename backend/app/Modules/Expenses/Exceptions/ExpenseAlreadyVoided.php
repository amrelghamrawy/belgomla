<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Exceptions;

use App\Support\ApiException;
use Carbon\CarbonImmutable;

/** D100 c: an expense is voided once: `409 already_voided` with `details.voided_at`. */
final class ExpenseAlreadyVoided extends ApiException
{
    public static function at(CarbonImmutable $voidedAt): self
    {
        return new self(409, 'already_voided', 'تم إلغاء هذا المصروف من قبل', ['voided_at' => $voidedAt->toIso8601String()]);
    }
}
