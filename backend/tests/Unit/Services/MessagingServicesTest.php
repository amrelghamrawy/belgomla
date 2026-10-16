<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Services\Fallback;
use App\Modules\Messaging\Services\Hypersender\HypersenderSmsDriver;
use App\Modules\Messaging\Services\Hypersender\HypersenderWhatsAppDriver;
use App\Modules\Messaging\Services\LogDriver;
use App\Modules\Messaging\Services\MessageContext;
use App\Modules\Messaging\Services\MessageDrivers;
use App\Modules\Messaging\Services\WaGateway\WaGatewayDriver;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/*
| Small Messaging services: the driver picker, the fallback rule, the log driver and the tenant runner.
*/

test('the log driver serves both channels when messaging.driver is log', function (Channel $channel): void {
    config(['messaging.driver' => 'log']);

    expect(app(MessageDrivers::class)->for($channel))->toBeInstanceOf(LogDriver::class);
})->with([Channel::WhatsApp, Channel::Sms]);

test('D11 hypersender has one driver per channel', function (Channel $channel, string $driver): void {
    useHypersender();

    expect(app(MessageDrivers::class)->for($channel))->toBeInstanceOf($driver);
})->with([
    'whatsapp' => [Channel::WhatsApp, HypersenderWhatsAppDriver::class],
    'sms' => [Channel::Sms, HypersenderSmsDriver::class],
]);

test('an unknown messaging driver is a configuration error', function (): void {
    config(['messaging.driver' => 'carrier-pigeon']);

    app(MessageDrivers::class)->for(Channel::Sms);
})->throws(RuntimeException::class, 'Unknown messaging driver [carrier-pigeon].');

test('BR-CU07 D70 the fallback chain is store number, platform number, then sms', function (Channel $channel, Provider $provider, DriverResult $result, ?array $next): void {
    useHypersender();
    $message = new OutboundMessage(['channel' => $channel, 'provider' => $provider]);

    $route = app(Fallback::class)->next($message, $result);

    expect($route === null ? null : [$route->channel, $route->provider])->toBe($next);
})->with([
    'store number, send failed' => [Channel::WhatsApp, Provider::WaGateway, DriverResult::failed('session_not_open'), [Channel::WhatsApp, Provider::Hypersender]],
    'store number, gateway down' => [Channel::WhatsApp, Provider::WaGateway, DriverResult::failed('gateway_unreachable'), [Channel::WhatsApp, Provider::Hypersender]],
    'store number, not on whatsapp' => [Channel::WhatsApp, Provider::WaGateway, DriverResult::failed('not_on_whatsapp', true), [Channel::Sms, Provider::Hypersender]],
    'store number, sent' => [Channel::WhatsApp, Provider::WaGateway, DriverResult::sent('msg-1'), null],
    'platform, not on whatsapp' => [Channel::WhatsApp, Provider::Hypersender, DriverResult::failed('not_on_whatsapp', true), [Channel::Sms, Provider::Hypersender]],
    'platform, send failed' => [Channel::WhatsApp, Provider::Hypersender, DriverResult::failed('provider_error'), [Channel::Sms, Provider::Hypersender]],
    'platform, poll timeout' => [Channel::WhatsApp, Provider::Hypersender, DriverResult::failed('poll_timeout'), [Channel::Sms, Provider::Hypersender]],
    'platform, sent' => [Channel::WhatsApp, Provider::Hypersender, DriverResult::sent('msg-1'), null],
    'platform, queued' => [Channel::WhatsApp, Provider::Hypersender, DriverResult::queued('req-1'), null],
    'platform, delivered' => [Channel::WhatsApp, Provider::Hypersender, DriverResult::delivered('msg-1'), null],
    'sms, failed' => [Channel::Sms, Provider::Hypersender, DriverResult::failed('sms_failed'), null],
    'sms, not on whatsapp' => [Channel::Sms, Provider::Hypersender, DriverResult::failed('not_on_whatsapp', true), null],
]);

test('the fallback copy uses the configured platform provider', function (): void {
    config(['messaging.driver' => 'log']);
    $message = new OutboundMessage(['channel' => Channel::WhatsApp, 'provider' => Provider::WaGateway]);

    $route = app(Fallback::class)->next($message, DriverResult::failed('session_not_open'));

    expect($route?->provider)->toBe(Provider::Log);
});

test('D70 an otp or invitation goes from the store number when it is connected', function (MessagePurpose $purpose): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    $driver = $this->inTenant(fn () => app(MessageDrivers::class)->choose(Channel::WhatsApp, $purpose));

    expect($driver)->toBeInstanceOf(WaGatewayDriver::class);
})->with([MessagePurpose::Otp, MessagePurpose::Invitation]);

test('D70 the platform number is used while the store number is not connected', function (string $state): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->{$state}()->create());

    $driver = $this->inTenant(fn () => app(MessageDrivers::class)->choose(Channel::WhatsApp, MessagePurpose::Otp));

    expect($driver)->toBeInstanceOf(LogDriver::class);
})->with(['pairing', 'disconnected', 'loggedOut', 'error']);

test('D70 the platform number is used when the store has never linked one', function (): void {
    useWaGateway();

    expect($this->inTenant(fn () => app(MessageDrivers::class)->choose(Channel::WhatsApp, MessagePurpose::Otp)))->toBeInstanceOf(LogDriver::class);
});

test('D70 platform alerts never go from the store number', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    $driver = $this->inTenant(fn () => app(MessageDrivers::class)->choose(Channel::WhatsApp, MessagePurpose::SystemAlert));

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

test('D70 an sms never goes through the store number', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    $driver = $this->inTenant(fn () => app(MessageDrivers::class)->choose(Channel::Sms, MessagePurpose::Otp));

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

test('D70 a disabled gateway is never chosen, even for a connected store', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    config(['messaging.wa_gateway.enabled' => false]);

    $driver = $this->inTenant(fn () => app(MessageDrivers::class)->choose(Channel::WhatsApp, MessagePurpose::Otp));

    expect($driver)->toBeInstanceOf(LogDriver::class);
});

test('a logged message is handled by the driver of its provider', function (Provider $provider, Channel $channel, string $driver): void {
    useHypersender();

    expect(app(MessageDrivers::class)->forMessage(new OutboundMessage(['provider' => $provider, 'channel' => $channel])))->toBeInstanceOf($driver);
})->with([
    'store number' => [Provider::WaGateway, Channel::WhatsApp, WaGatewayDriver::class],
    'platform whatsapp' => [Provider::Hypersender, Channel::WhatsApp, HypersenderWhatsAppDriver::class],
    'platform sms' => [Provider::Hypersender, Channel::Sms, HypersenderSmsDriver::class],
]);

test('the log driver writes one line per message to the messages channel', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->once()->with("[otp] whatsapp +201012345678 {$message->uuid}: رمز التحقق: 123456");
    Log::shouldReceive('channel')->once()->with('messages')->andReturn($logger);

    $result = (new LogDriver)->send($message, new MessagePayload('+201012345678', MessagePurpose::Otp, 'رمز التحقق: 123456'));

    expect([$result->ok, $result->final, $result->providerMessageId])->toBe([true, true, 'log-'.$message->uuid]);
});

test('the messages channel is storage/logs/messages.log', function (): void {
    expect(config()->string('logging.channels.messages.path'))->toBe(storage_path('logs/messages.log'));
});

test('the log driver reports every message as sent and is the log provider', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('log-abc')->create());

    expect((new LogDriver)->status($message)->providerMessageId)->toBe('log-abc')
        ->and((new LogDriver)->provider())->toBe(Provider::Log);
});

test('the message context runs inside the tenant of the message and restores the previous context', function (): void {
    $other = $this->otherTenant();
    $message = $this->asTenant($other, fn () => OutboundMessage::factory()->create());
    $seen = null;

    app(MessageContext::class)->run($message->id, function (OutboundMessage $loaded) use (&$seen): void {
        $seen = [app(TenantContext::class)->id(), $loaded->id];
    });

    expect($seen)->toBe([$other->id, $message->id])
        ->and(app(TenantContext::class)->has())->toBeFalse();
});

test('the message context skips a message that no longer exists', function (): void {
    $called = false;

    app(MessageContext::class)->run(999_999, function () use (&$called): void {
        $called = true;
    });

    expect($called)->toBeFalse();
});
