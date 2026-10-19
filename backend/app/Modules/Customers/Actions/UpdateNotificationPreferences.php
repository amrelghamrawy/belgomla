<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Models\Customer;

/**
 * BR-N03: the customer turns WhatsApp messages about their orders on or off. Setting the same value again changes
 * nothing and writes no audit; a change is audited `customer.preferences_changed` with the customer as actor.
 */
final readonly class UpdateNotificationPreferences
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Customer $customer, bool $orderNotifications): Customer
    {
        if ($customer->order_notifications_opt_out === ! $orderNotifications) {
            return $customer;
        }

        $customer->forceFill(['order_notifications_opt_out' => ! $orderNotifications])->save();
        $this->audit->log('customer.preferences_changed', $customer, ['order_notifications' => ! $orderNotifications], ['order_notifications' => $orderNotifications]);

        return $customer;
    }
}
