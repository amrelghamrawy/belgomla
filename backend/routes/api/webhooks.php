<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\Webhooks\HypersenderWebhookController;
use App\Modules\Messaging\Http\Controllers\Webhooks\WaGatewayWebhookController;
use App\Modules\Messaging\Http\Middleware\VerifyHypersenderWebhook;
use App\Modules\Messaging\Http\Middleware\VerifyWaGatewaySignature;
use Illuminate\Support\Facades\Route;

/*
| Provider webhooks (08-api-contract.md §13). Mounted under /api/webhooks on the platform host
| hooks.{base_domain}, outside the tenant middleware: the tenant is found through the message itself.
*/

Route::post('hypersender/{channel}', [HypersenderWebhookController::class, 'store'])
    ->whereIn('channel', ['whatsapp', 'sms'])
    ->middleware(VerifyHypersenderWebhook::class)
    ->name('hypersender');

// D70: the store-number gateway on the same machine, HMAC-signed.
Route::post('wa-gateway', [WaGatewayWebhookController::class, 'store'])
    ->middleware(VerifyWaGatewaySignature::class)
    ->name('wa-gateway');
