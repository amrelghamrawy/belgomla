<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\GatewayEventData;
use App\Modules\Messaging\Data\StoreWhatsAppStatus;
use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Exceptions\WaGatewayUnavailable;
use App\Modules\Messaging\Queries\StoreWhatsAppConnectionQuery;
use App\Modules\Messaging\Services\WaGateway\WaGatewayClient;

/**
 * D70: what the settings page shows, polled every few seconds while pairing. During a pairing the gateway is
 * asked for the live QR; when it reports the session open (or gone) the result is recorded right away
 * through RecordConnectionEvent, so the page does not depend on the webhook having arrived. Outside a
 * pairing nothing is called.
 */
final readonly class CheckWhatsAppPairing
{
    public function __construct(
        private StoreWhatsAppConnectionQuery $connections,
        private WaGatewayClient $gateway,
        private RecordConnectionEvent $record,
    ) {}

    public function handle(): StoreWhatsAppStatus
    {
        if (! config()->boolean('messaging.wa_gateway.enabled')) {
            return new StoreWhatsAppStatus(false, null);
        }

        $connection = $this->connections->current();

        if ($connection?->status !== ConnectionStatus::Pairing) {
            return new StoreWhatsAppStatus(true, $connection);
        }

        try {
            $session = $this->gateway->session($connection->session_key);
        } catch (WaGatewayUnavailable) {
            return new StoreWhatsAppStatus(true, $connection);
        }

        $event = match ($session === null ? 'closed' : $session['state']) {
            'open' => new GatewayEventData(GatewayEventData::OPEN, $connection->session_key, phone: $session['phone'] ?? null),
            'closed' => new GatewayEventData(GatewayEventData::CLOSED, $connection->session_key, reason: 'qr_expired'),
            default => null,
        };

        if (! $event instanceof GatewayEventData) {
            return new StoreWhatsAppStatus(true, $connection, $session['qr'] ?? null);
        }

        return new StoreWhatsAppStatus(true, $this->record->handle($connection, $event));
    }
}
