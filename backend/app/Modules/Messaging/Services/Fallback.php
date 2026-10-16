<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\FallbackRoute;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;

/**
 * BR-CU07 and D70: the channel order is store number → platform number → SMS. A failed message from the store
 * number is re-sent from the platform number, unless the recipient is not on WhatsApp at all, then it goes
 * straight to SMS. A failed platform WhatsApp message is re-sent as SMS. An SMS failure is final, so the chain
 * always ends.
 */
final readonly class Fallback
{
    public function __construct(private MessageDrivers $drivers) {}

    public function next(OutboundMessage $message, DriverResult $result): ?FallbackRoute
    {
        if ($result->ok || $message->channel === Channel::Sms) {
            return null;
        }

        if ($message->provider === Provider::WaGateway && ! $result->notOnWhatsApp) {
            return new FallbackRoute(Channel::WhatsApp, $this->drivers->for(Channel::WhatsApp)->provider());
        }

        return new FallbackRoute(Channel::Sms, $this->drivers->for(Channel::Sms)->provider());
    }
}
