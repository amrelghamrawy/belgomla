<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Services\Fallback;
use App\Modules\Messaging\Services\Hypersender\HypersenderSmsDriver;
use App\Modules\Messaging\Services\Hypersender\HypersenderWhatsAppDriver;
use App\Modules\Messaging\Services\LogDriver;
use App\Modules\Messaging\Services\MessageContext;
use App\Modules\Messaging\Services\MessageDrivers;
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

test('BR-CU07 only a failed whatsapp message falls back to sms', function (Channel $channel, DriverResult $result, bool $fallsBack): void {
    $message = new OutboundMessage(['channel' => $channel]);

    expect((new Fallback)->shouldFallBack($message, $result))->toBe($fallsBack);
})->with([
    'whatsapp, not on whatsapp' => [Channel::WhatsApp, DriverResult::failed('not_on_whatsapp', true), true],
    'whatsapp, send failed' => [Channel::WhatsApp, DriverResult::failed('provider_error'), true],
    'whatsapp, poll timeout' => [Channel::WhatsApp, DriverResult::failed('poll_timeout'), true],
    'whatsapp, sent' => [Channel::WhatsApp, DriverResult::sent('msg-1'), false],
    'whatsapp, queued' => [Channel::WhatsApp, DriverResult::queued('req-1'), false],
    'whatsapp, delivered' => [Channel::WhatsApp, DriverResult::delivered('msg-1'), false],
    'sms, failed' => [Channel::Sms, DriverResult::failed('sms_failed'), false],
    'sms, not on whatsapp' => [Channel::Sms, DriverResult::failed('not_on_whatsapp', true), false],
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
