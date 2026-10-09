<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageDriver;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Services\Hypersender\HypersenderSmsDriver;
use App\Modules\Messaging\Services\Hypersender\HypersenderWhatsAppDriver;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Picks the driver for a channel from `messaging.driver` (D11): Hypersender in production, the log driver in
 * development and tests.
 */
final readonly class MessageDrivers
{
    public function __construct(private Container $container) {}

    public function for(Channel $channel): MessageDriver
    {
        $class = match (config()->string('messaging.driver')) {
            'log' => LogDriver::class,
            'hypersender' => $channel === Channel::WhatsApp ? HypersenderWhatsAppDriver::class : HypersenderSmsDriver::class,
            default => throw new RuntimeException('Unknown messaging driver ['.config()->string('messaging.driver').'].'),
        };

        return $this->container->make($class);
    }
}
