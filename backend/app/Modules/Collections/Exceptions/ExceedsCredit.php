<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use App\Support\ApiException;

/** BR-C07: applying more than is left of a customer credit: `422 exceeds_credit` with `details.remaining`. */
final class ExceedsCredit extends ApiException
{
    public static function remaining(string $remaining): self
    {
        return new self(422, 'exceeds_credit', "المبلغ أكبر من المتبقي في رصيد العميل ({$remaining})", ['remaining' => $remaining]);
    }
}
