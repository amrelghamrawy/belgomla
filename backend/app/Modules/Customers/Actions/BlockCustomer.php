<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU05: the merchant blocks a customer with a reason; a blocked customer cannot log in or order, and an
 * open session loses the cart and addresses (EnsureCustomerActive). The status before the block is kept for
 * UnblockCustomer. Blocking twice is `409`. Audited as `customer.blocked` (BR-L). Owns its transaction.
 */
final readonly class BlockCustomer
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Customer $customer, string $reason): Customer
    {
        $from = $customer->status;

        if (! $from->canTransitionTo(CustomerStatus::Blocked)) {
            throw InvalidCustomerTransition::from($from, CustomerStatus::Blocked);
        }

        return DB::transaction(function () use ($customer, $from, $reason): Customer {
            $customer->forceFill([
                'status' => CustomerStatus::Blocked,
                'status_before_block' => $from,
                'blocked_at' => now(),
                'blocked_reason' => $reason,
            ])->save();

            $this->audit->log('customer.blocked', $customer, ['status' => $from->value], ['status' => CustomerStatus::Blocked->value, 'reason' => $reason]);

            return $customer;
        });
    }
}
