<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\Hypersender;

use App\Modules\Messaging\Contracts\MessageDriver;
use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Support\PhoneNumber;

/**
 * WhatsApp through the platform's Hypersender instance (D12). v2 is asynchronous: a send returns a queued
 * request uuid, and `status()` reads `queued-requests/{uuid}` until it has a result. Plain text only;
 * Hypersender's own OTP endpoints are not used (Tech §13).
 */
final readonly class HypersenderWhatsAppDriver implements MessageDriver
{
    public function __construct(private HypersenderClient $client, private HypersenderErrors $errors) {}

    public function provider(): Provider
    {
        return Provider::Hypersender;
    }

    public function send(OutboundMessage $message, MessagePayload $payload): DriverResult
    {
        $endpoint = config()->boolean('messaging.hypersender.safe_send') ? 'send-text-safe' : 'send-text';

        $response = $this->client->whatsapp()->post($endpoint, [
            'chatId' => PhoneNumber::whatsAppChatId($payload->toPhone),
            'text' => $payload->text,
        ]);

        return $this->errors->fromWhatsAppSend($response->status(), $this->json($response->json()));
    }

    public function status(OutboundMessage $message): DriverResult
    {
        if ($message->provider_request_id === null) {
            return DriverResult::failed('missing_request_id');
        }

        $response = $this->client->whatsapp()->get('queued-requests/'.$message->provider_request_id);

        if ($response->notFound()) {
            return DriverResult::failed('request_not_found', providerRequestId: $message->provider_request_id);
        }

        if ($response->failed()) {
            return DriverResult::queued($message->provider_request_id);
        }

        return $this->errors->fromQueuedRequest($this->json($response->json()));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(mixed $decoded): array
    {
        return is_array($decoded) ? $decoded : [];
    }
}
