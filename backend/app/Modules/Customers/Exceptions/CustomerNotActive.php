<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Support\ApiException;

/**
 * BR-CU05: a logged-in customer who is not (or no longer) active cannot use the cart or addresses,
 * e.g. blocked mid-session or still waiting for approval. The code is the status.
 */
final class CustomerNotActive extends ApiException
{
    public static function for(CustomerStatus $status): self
    {
        return new self(403, $status->value, "لا يمكن إتمام هذا الإجراء، حالة حسابك «{$status->label()}»");
    }
}
