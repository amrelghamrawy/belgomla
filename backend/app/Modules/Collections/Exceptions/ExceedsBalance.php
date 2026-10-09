<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use App\Support\ApiException;

/** BR-C03: collecting more than what is left on the order is rejected: `422 exceeds_balance` with `details.remaining`. */
final class ExceedsBalance extends ApiException
{
    public static function remaining(string $remaining): self
    {
        return new self(
            422,
            'exceeds_balance',
            "المبلغ أكبر من المتبقي على الطلب ({$remaining})",
            ['remaining' => $remaining],
        );
    }
}
