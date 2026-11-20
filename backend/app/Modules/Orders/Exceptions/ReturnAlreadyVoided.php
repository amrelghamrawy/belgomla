<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Support\ApiException;
use Carbon\CarbonImmutable;

/** BR-R06: a return is voided once: `409 already_voided` with `details.voided_at`, like a collection (BR-C02). */
final class ReturnAlreadyVoided extends ApiException
{
    public static function at(CarbonImmutable $voidedAt): self
    {
        return new self(409, 'already_voided', 'تم إلغاء هذا المرتجع من قبل', ['voided_at' => $voidedAt->toIso8601String()]);
    }
}
