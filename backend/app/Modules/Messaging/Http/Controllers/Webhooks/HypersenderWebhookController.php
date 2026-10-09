<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Webhooks;

use App\Modules\Messaging\Actions\AcceptHypersenderWebhook;
use App\Modules\Messaging\Http\Requests\HypersenderWebhookRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class HypersenderWebhookController
{
    /** POST /api/webhooks/hypersender/{whatsapp|sms} on the hooks host — Tech §13, BR-CU07 */
    public function store(HypersenderWebhookRequest $request, AcceptHypersenderWebhook $accept): JsonResponse
    {
        $accept->handle($request->channel(), $request->payload());

        return ApiResponse::data(['received' => true]);
    }
}
