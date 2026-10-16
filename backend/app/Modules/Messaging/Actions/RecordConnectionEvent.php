<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\GatewayEventData;
use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Exceptions\WaGatewayUnavailable;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Queries\StoreWhatsAppConnectionQuery;
use App\Modules\Messaging\Services\WaGateway\WaGatewayClient;
use Illuminate\Support\Facades\DB;

/**
 * D70: applies a session event from wa-gateway to the store number, inside its tenant. Owns its transaction
 * and locks the row, so a webhook and a dashboard poll reporting the same event apply once.
 *   session.open    → connected with the phone; a phone already connected on another store → `error`
 *                     (`phone_in_use`) and the gateway drops the session (tasks/12 lesson 2).
 *   session.closed  → fatal: `logged_out` with the reason; an expired QR: `disconnected`; anything else
 *                     (reconnects exhausted) keeps the status for the reconcile to restart.
 * A number the merchant unlinked, or one in `error`, ignores late events.
 */
final readonly class RecordConnectionEvent
{
    public function __construct(private StoreWhatsAppConnectionQuery $connections, private WaGatewayClient $gateway) {}

    public function handle(WhatsAppConnection $connection, GatewayEventData $event): WhatsAppConnection
    {
        [$recorded, $refused] = DB::transaction(function () use ($connection, $event): array {
            $locked = WhatsAppConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if (in_array($locked->status, [ConnectionStatus::Disconnected, ConnectionStatus::Error], true)) {
                return [$locked, false];
            }

            match ($event->event) {
                GatewayEventData::OPEN => $this->opened($locked, $event->phone),
                GatewayEventData::CLOSED => $this->closed($locked, $event->reason ?? 'unknown', $event->fatal),
                default => null,
            };

            $locked->last_event_at = now();
            $locked->save();

            return [$locked, $locked->status === ConnectionStatus::Error];
        });

        if ($refused) {
            $this->dropSession($recorded->session_key);
        }

        return $recorded;
    }

    private function opened(WhatsAppConnection $connection, ?string $phone): void
    {
        if ($phone !== null && $this->connections->phoneConnectedElsewhere($phone, $connection->id)) {
            $connection->fill(['status' => ConnectionStatus::Error, 'phone' => $phone, 'disconnect_reason' => 'phone_in_use']);

            return;
        }

        $connection->fill([
            'status' => ConnectionStatus::Connected,
            'phone' => $phone,
            'disconnect_reason' => null,
            'connected_at' => $connection->status === ConnectionStatus::Connected ? $connection->connected_at : now(),
        ]);
    }

    private function closed(WhatsAppConnection $connection, string $reason, bool $fatal): void
    {
        $connection->disconnect_reason = $reason;

        if ($fatal) {
            $connection->status = ConnectionStatus::LoggedOut;
        } elseif ($reason === 'qr_expired') {
            $connection->status = ConnectionStatus::Disconnected;
            $connection->phone = null;
        }
    }

    private function dropSession(string $key): void
    {
        try {
            $this->gateway->remove($key);
        } catch (WaGatewayUnavailable) {
            // The reconcile removes it: an `error` row is not one the gateway should run.
        }
    }
}
