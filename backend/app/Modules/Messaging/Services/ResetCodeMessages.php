<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Tenancy\Contracts\ResetCodeSender;

/**
 * D112, BR-T10: a dashboard password reset code goes out like a customer OTP: WhatsApp from the store's own number when
 * it is linked (D70), the platform chain otherwise, SMS as the fallback.
 */
final readonly class ResetCodeMessages implements ResetCodeSender
{
    public function __construct(private Messenger $messenger) {}

    public function send(string $phone, string $text): void
    {
        $this->messenger->send(new MessagePayload($phone, MessagePurpose::Otp, $text, Channel::WhatsApp));
    }
}
