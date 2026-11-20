<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Modules\Customers\Models\Customer;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * BR-R06: voiding a return is a merchant action (rules/02 §2: every state change has a policy). The dashboard user
 * reaches the tenant's returns through the guard and tenant scope; a customer never may. Whether the return is still
 * live is answered by VoidReturn under the row lock (`409 already_voided`), because a policy can only refuse with 403.
 */
final readonly class OrderReturnPolicy
{
    public function void(Authenticatable $user): Response
    {
        return $user instanceof Customer ? Response::deny() : Response::allow();
    }
}
