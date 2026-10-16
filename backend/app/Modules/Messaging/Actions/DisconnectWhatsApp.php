<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Messaging\Data\StoreWhatsAppStatus;
use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Exceptions\WaGatewayUnavailable;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Queries\StoreWhatsAppConnectionQuery;
use App\Modules\Messaging\Services\WaGateway\WaGatewayClient;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * D70: unlinks the store's WhatsApp number. The gateway logs the device out of the phone, then deletes the
 * session (tasks/12 lesson 1). The row is `disconnected` even when the gateway could not be reached; the
 * reconcile removes the leftover session later, and `loggedOutOnPhone: false` tells the merchant to remove
 * the linked device from the phone. Messages fall back to the platform number at once. Audited.
 */
final readonly class DisconnectWhatsApp
{
    public function __construct(
        private WaGatewayClient $gateway,
        private StoreWhatsAppConnectionQuery $connections,
        private AuditLogger $audit,
    ) {}

    public function handle(): StoreWhatsAppStatus
    {
        $connection = config()->boolean('messaging.wa_gateway.enabled') ? $this->connections->current() : null;

        if (! $connection instanceof WhatsAppConnection) {
            throw ApiException::notFound();
        }

        if ($connection->status === ConnectionStatus::Disconnected) {
            return new StoreWhatsAppStatus(true, $connection, loggedOutOnPhone: false);
        }

        try {
            $loggedOut = $this->gateway->remove($connection->session_key);
        } catch (WaGatewayUnavailable) {
            $loggedOut = false;
        }

        DB::transaction(function () use ($connection): void {
            $old = ['status' => $connection->status->value, 'phone' => $connection->phone];

            $connection->fill([
                'status' => ConnectionStatus::Disconnected,
                'phone' => null,
                'disconnect_reason' => 'unlinked',
                'connected_at' => null,
                'last_event_at' => now(),
            ])->save();

            $this->audit->log('whatsapp.unlinked', $connection, $old, ['status' => ConnectionStatus::Disconnected->value, 'phone' => null]);
        });

        return new StoreWhatsAppStatus(true, $connection, loggedOutOnPhone: $loggedOut);
    }
}
