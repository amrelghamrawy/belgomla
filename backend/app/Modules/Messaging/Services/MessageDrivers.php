<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageDriver;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Queries\ConnectedStoreNumberQuery;
use App\Modules\Messaging\Services\Hypersender\HypersenderSmsDriver;
use App\Modules\Messaging\Services\Hypersender\HypersenderWhatsAppDriver;
use App\Modules\Messaging\Services\WaGateway\WaGatewayDriver;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Picks drivers. The platform driver of a channel comes from `messaging.driver` (D11): Hypersender in
 * production, the log driver in development and tests. A new OTP or invitation goes from the store's own
 * number when it is connected (D70); platform alerts never do.
 */
final readonly class MessageDrivers
{
    private const array STORE_NUMBER_PURPOSES = [MessagePurpose::Otp, MessagePurpose::Invitation];

    public function __construct(private Container $container, private ConnectedStoreNumberQuery $storeNumber) {}

    /** The platform driver of a channel. */
    public function for(Channel $channel): MessageDriver
    {
        $class = match (config()->string('messaging.driver')) {
            'log' => LogDriver::class,
            'hypersender' => $channel === Channel::WhatsApp ? HypersenderWhatsAppDriver::class : HypersenderSmsDriver::class,
            default => throw new RuntimeException('Unknown messaging driver ['.config()->string('messaging.driver').'].'),
        };

        return $this->container->make($class);
    }

    /** The driver a new message of the current tenant starts with. */
    public function choose(Channel $channel, MessagePurpose $purpose): MessageDriver
    {
        if ($channel === Channel::WhatsApp
            && in_array($purpose, self::STORE_NUMBER_PURPOSES, true)
            && $this->storeNumber->get() instanceof WhatsAppConnection) {
            return $this->container->make(WaGatewayDriver::class);
        }

        return $this->for($channel);
    }

    /** The driver that handles an existing message, by the provider it was logged with. */
    public function forMessage(OutboundMessage $message): MessageDriver
    {
        return $message->provider === Provider::WaGateway
            ? $this->container->make(WaGatewayDriver::class)
            : $this->for($message->channel);
    }
}
