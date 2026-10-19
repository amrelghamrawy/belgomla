<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Contracts;

/**
 * D70, BR-N02: who to tell when a store's WhatsApp number drops for good (logged out on the phone). Orders fills it
 * with a dashboard notification (MerchantConnectionAlerts); without it nobody is told. Called inside the tenant.
 */
interface ConnectionAlerts
{
    public function dropped(?string $phone, string $reason): void;
}
