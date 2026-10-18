<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Exceptions\WaGatewayUnavailable;
use App\Modules\Messaging\Queries\StoreWhatsAppConnectionQuery;
use App\Modules\Messaging\Services\WaGateway\WaGatewayClient;

/**
 * D70 for `platform:monitor` (tasks/12 slice 6): whether wa-gateway answers, and how many of the store numbers
 * Laravel holds as connected it actually has open. Platform reads this instead of the gateway client, which
 * stays inside Messaging.
 */
final readonly class StoreNumberHealth
{
    public function __construct(private WaGatewayClient $gateway, private StoreWhatsAppConnectionQuery $connections) {}

    /**
     * @return array{reachable: bool, open: int, connected: int}|null null when wa-gateway is disabled
     */
    public function get(): ?array
    {
        if (! config()->boolean('messaging.wa_gateway.enabled')) {
            return null;
        }

        $connected = $this->connections->connectedCount();

        try {
            $open = $this->gateway->health()['open'];
        } catch (WaGatewayUnavailable) {
            return ['reachable' => false, 'open' => 0, 'connected' => $connected];
        }

        return ['reachable' => true, 'open' => $open, 'connected' => $connected];
    }
}
