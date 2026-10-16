<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\WaGateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for wa-gateway (D70, `wa-gateway/README.md`): 127.0.0.1 only, `Authorization: Bearer <token>`.
 */
final readonly class WaGatewayClient
{
    public function http(): PendingRequest
    {
        return Http::baseUrl(mb_rtrim(config()->string('messaging.wa_gateway.base_url'), '/'))
            ->withToken(config()->string('messaging.wa_gateway.token'))
            ->acceptJson()
            ->asJson()
            ->timeout(config()->integer('messaging.wa_gateway.timeout'));
    }
}
