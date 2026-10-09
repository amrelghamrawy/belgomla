<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Data;

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;

/**
 * What to send and to whom. `channel` is WhatsApp unless the customer asked for SMS (BR-CU07 step 3);
 * WhatsApp falls back to SMS on its own.
 */
final readonly class MessagePayload
{
    public function __construct(
        public string $toPhone,
        public MessagePurpose $purpose,
        public string $text,
        public Channel $channel = Channel::WhatsApp,
    ) {}

    public static function fromMessage(OutboundMessage $message): self
    {
        return new self($message->to_phone, $message->purpose, (string) $message->body, $message->channel);
    }
}
