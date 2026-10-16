<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Messaging\Data\StoreWhatsAppStatus;
use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Exceptions\WhatsAppAlreadyConnected;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Queries\StoreWhatsAppConnectionQuery;
use App\Modules\Messaging\Services\WaGateway\WaGatewayClient;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D70: starts linking the store's WhatsApp number. The gateway opens a session under a fresh key (or the
 * current one while a pairing is still running) and the row moves to `pairing`; the QR then comes from
 * CheckWhatsAppPairing. A connected number must be unlinked first. The gateway is asked before anything is
 * saved, so a gateway that is down or full changes nothing. Owns its transaction; audited.
 */
final readonly class StartWhatsAppPairing
{
    public function __construct(
        private WaGatewayClient $gateway,
        private StoreWhatsAppConnectionQuery $connections,
        private AuditLogger $audit,
    ) {}

    public function handle(): StoreWhatsAppStatus
    {
        if (! config()->boolean('messaging.wa_gateway.enabled')) {
            throw ApiException::notFound();
        }

        $connection = $this->connections->current();

        if ($connection?->status === ConnectionStatus::Connected) {
            throw WhatsAppAlreadyConnected::make();
        }

        $key = $connection?->status === ConnectionStatus::Pairing ? $connection->session_key : (string) Str::uuid();
        $session = $this->gateway->start($key);

        $connection = DB::transaction(function () use ($connection, $key): WhatsAppConnection {
            $connection ??= new WhatsAppConnection;
            $old = $connection->exists ? ['status' => $connection->status->value, 'phone' => $connection->phone] : null;

            $connection->fill([
                'session_key' => $key,
                'status' => ConnectionStatus::Pairing,
                'phone' => null,
                'disconnect_reason' => null,
                'connected_at' => null,
                'last_event_at' => now(),
            ])->save();

            $this->audit->log('whatsapp.pairing_started', $connection, $old, ['status' => ConnectionStatus::Pairing->value, 'phone' => null]);

            return $connection;
        });

        return new StoreWhatsAppStatus(true, $connection, $session['qr']);
    }
}
