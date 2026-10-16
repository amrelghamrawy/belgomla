<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Webhooks;

use App\Modules\Messaging\Actions\AcceptWaGatewayWebhook;
use App\Modules\Messaging\Data\GatewayEventData;
use App\Modules\Messaging\Http\Requests\WaGatewayWebhookRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class WaGatewayWebhookController
{
    /** POST /api/webhooks/wa-gateway on the hooks host — D70 */
    public function store(WaGatewayWebhookRequest $request, AcceptWaGatewayWebhook $accept): JsonResponse
    {
        $accept->handle(GatewayEventData::fromRequest($request));

        return ApiResponse::data(['received' => true]);
    }
}
