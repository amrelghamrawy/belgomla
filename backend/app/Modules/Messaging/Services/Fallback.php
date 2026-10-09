<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Models\OutboundMessage;

/**
 * BR-CU07 step 2: a WhatsApp message that failed — the number is not on WhatsApp, the send failed, or the
 * provider never answered — is re-sent as SMS. An SMS failure is final, so there is never a loop.
 */
final readonly class Fallback
{
    public function shouldFallBack(OutboundMessage $message, DriverResult $result): bool
    {
        return $message->channel === Channel::WhatsApp && ! $result->ok;
    }
}
