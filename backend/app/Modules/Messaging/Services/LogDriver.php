<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageDriver;
use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Support\Facades\Log;

/**
 * Development and test driver (`MESSAGING_DRIVER=log`): every message is one line in storage/logs/messages.log,
 * `[otp] whatsapp +201012345678 {uuid}: text`, so a developer (or a Customers test) can read the OTP.
 */
final readonly class LogDriver implements MessageDriver
{
    public function provider(): Provider
    {
        return Provider::Log;
    }

    public function send(OutboundMessage $message, MessagePayload $payload): DriverResult
    {
        Log::channel('messages')->info("[{$payload->purpose->value}] {$payload->channel->value} {$payload->toPhone} {$message->uuid}: {$payload->text}");

        return DriverResult::sent('log-'.$message->uuid);
    }

    public function status(OutboundMessage $message): DriverResult
    {
        return DriverResult::sent($message->provider_message_id ?? 'log-'.$message->uuid);
    }
}
