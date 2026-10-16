<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\WaGateway;

use App\Modules\Messaging\Contracts\MessageDriver;
use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Queries\ConnectedStoreNumberQuery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * WhatsApp from the store's own number (D70) through wa-gateway, which answers synchronously: the message is
 * sent or refused in the call. Any refusal — the number dropped, the gateway is down — fails the message at
 * once, so the platform number takes over without waiting for retries (BR-CU07). Only the per-number send
 * limit makes the job wait.
 */
final readonly class WaGatewayDriver implements MessageDriver
{
    public function __construct(private WaGatewayClient $client, private ConnectedStoreNumberQuery $storeNumber) {}

    public function provider(): Provider
    {
        return Provider::WaGateway;
    }

    public function send(OutboundMessage $message, MessagePayload $payload): DriverResult
    {
        $connection = $this->storeNumber->get();

        if (! $connection instanceof WhatsAppConnection) {
            return DriverResult::failed('session_not_open');
        }

        try {
            $response = $this->client->http()->post("sessions/{$connection->session_key}/messages", [
                'to' => $payload->toPhone,
                'text' => $payload->text,
            ]);
        } catch (ConnectionException) {
            return DriverResult::failed('gateway_unreachable');
        }

        return $this->result($response);
    }

    /** Never polled: a send is final when it returns. */
    public function status(OutboundMessage $message): DriverResult
    {
        return $message->provider_message_id === null
            ? DriverResult::failed('missing_message_id')
            : DriverResult::sent($message->provider_message_id);
    }

    private function result(Response $response): DriverResult
    {
        $id = $response->json('id');

        return match (true) {
            $response->successful() && is_string($id) && $id !== '' => DriverResult::sent($id),
            $response->status() === 422 => DriverResult::failed('not_on_whatsapp', notOnWhatsApp: true),
            $response->status() === 409 => DriverResult::failed('session_not_open'),
            $response->status() === 429 => DriverResult::busy($this->retryAfter($response)),
            default => DriverResult::failed('gateway_error'),
        };
    }

    private function retryAfter(Response $response): int
    {
        $seconds = $response->json('retry_after');

        return is_int($seconds) && $seconds > 0 ? $seconds : 60;
    }
}
