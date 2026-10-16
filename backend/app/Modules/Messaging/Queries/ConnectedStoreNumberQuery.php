<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Queries;

use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Models\WhatsAppConnection;

/**
 * D70: the current tenant's linked WhatsApp number, when it is connected and the gateway is enabled.
 */
final readonly class ConnectedStoreNumberQuery
{
    public function get(): ?WhatsAppConnection
    {
        if (! config()->boolean('messaging.wa_gateway.enabled')) {
            return null;
        }

        return WhatsAppConnection::query()->where('status', ConnectionStatus::Connected)->first();
    }
}
