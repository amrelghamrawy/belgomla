<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use App\Support\ApiException;
use Carbon\CarbonImmutable;

/** BR-C02: a collection is voided once: `409 already_voided` with `details.voided_at`. */
final class AlreadyVoided extends ApiException
{
    public static function at(CarbonImmutable $voidedAt): self
    {
        return new self(409, 'already_voided', 'تم إلغاء هذا التحصيل من قبل', ['voided_at' => $voidedAt->toIso8601String()]);
    }
}
