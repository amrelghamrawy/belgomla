<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;

/**
 * One provider channel (Tech §13). Business code never calls a driver: it goes through Messenger, and the
 * Messaging jobs call the driver. Moving to the Meta Cloud API (D46) is a new implementation of this.
 */
interface MessageDriver
{
    public function provider(): Provider;

    public function send(OutboundMessage $message, MessagePayload $payload): DriverResult;

    /** Re-check a message the provider accepted but has not finished (async APIs). */
    public function status(OutboundMessage $message): DriverResult;
}
