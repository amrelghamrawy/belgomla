<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Messaging\Contracts\ConnectionAlerts;
use App\Modules\Orders\Actions\NotifyMerchants;
use App\Modules\Orders\Enums\NotificationType;

/**
 * Messaging's ConnectionAlerts (D70, BR-N02): a store number that dropped for good is a `whatsapp_dropped`
 * notification in the dashboard bell, with the phone and the gateway's reason, linking to settings.
 */
final readonly class MerchantConnectionAlerts implements ConnectionAlerts
{
    public function __construct(private NotifyMerchants $notify) {}

    public function dropped(?string $phone, string $reason): void
    {
        $this->notify->handle(NotificationType::WhatsappDropped, ['phone' => $phone, 'reason' => $reason]);
    }
}
