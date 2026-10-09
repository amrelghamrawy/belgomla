<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\Hypersender;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP clients for one Hypersender WhatsApp instance (API v2, asynchronous) and one SMS instance (API v1,
 * the Android app on the platform phone). Auth is `Authorization: Bearer <instance token>` (D11, Tech §13).
 * The official SDK is not used: it does not wrap the v2 queued requests and has bugs (tasks/4-messaging.md).
 */
final readonly class HypersenderClient
{
    public function whatsapp(): PendingRequest
    {
        return $this->client(
            'whatsapp/v2/'.config()->string('messaging.hypersender.whatsapp_instance'),
            config()->string('messaging.hypersender.token'),
        );
    }

    public function sms(): PendingRequest
    {
        return $this->client(
            'sms/v1/'.config()->string('messaging.hypersender.sms_instance'),
            config()->string('messaging.hypersender.sms_token'),
        );
    }

    private function client(string $path, string $token): PendingRequest
    {
        return Http::baseUrl(mb_rtrim(config()->string('messaging.hypersender.base_url'), '/').'/'.$path)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(config()->integer('messaging.hypersender.timeout'));
    }
}
