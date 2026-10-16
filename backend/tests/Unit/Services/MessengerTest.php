<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Exceptions\InvalidPhoneNumber;
use App\Modules\Messaging\Exceptions\MessagingRateLimited;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Services\Messenger;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

function otp(string $phone = '01012345678', Channel $channel = Channel::WhatsApp): MessagePayload
{
    return new MessagePayload($phone, MessagePurpose::Otp, 'رمز التحقق: 123456', $channel);
}

test('a send logs the message for the tenant and queues it', function (): void {
    $message = $this->inTenant(fn () => app(Messenger::class)->send(otp())->fresh());

    expect($message)->not->toBeNull()
        ->and($message?->tenant_id)->toBe($this->tenant->id)
        ->and($message?->to_phone)->toBe('+201012345678')
        ->and($message?->purpose)->toBe(MessagePurpose::Otp)
        ->and($message?->channel)->toBe(Channel::WhatsApp)
        ->and($message?->provider)->toBe(Provider::Log)
        ->and($message?->status)->toBe(MessageStatus::Queued)
        ->and($message?->body)->toBe('رمز التحقق: 123456')
        ->and($message?->uuid)->toBeUuid();
    Queue::assertPushedOn('messaging', SendMessage::class, fn (SendMessage $job): bool => $job->messageId === $message?->id);
});

test('BR-CU07 the customer can ask for sms directly', function (): void {
    $message = $this->inTenant(fn () => app(Messenger::class)->send(otp(channel: Channel::Sms)));

    expect($message->channel)->toBe(Channel::Sms);
});

test('D11 the provider is the configured driver', function (): void {
    useHypersender();

    expect($this->inTenant(fn () => app(Messenger::class)->send(otp()))->provider)->toBe(Provider::Hypersender);
});

test('a number that is not an egyptian mobile is refused before anything is logged', function (): void {
    expect(fn () => $this->inTenant(fn () => app(Messenger::class)->send(otp('0223456789'))))
        ->toThrow(InvalidPhoneNumber::class);

    $this->assertDatabaseCount('outbound_messages', 0);
    Queue::assertNothingPushed();
});

test('BR-CU07 the sixth message to a phone within an hour is refused and not logged', function (): void {
    $this->inTenant(function (): void {
        foreach (range(1, 5) as $ignored) {
            app(Messenger::class)->send(otp());
        }
    });

    expect(fn () => $this->inTenant(fn () => app(Messenger::class)->send(otp('+20 10 1234 5678'))))
        ->toThrow(MessagingRateLimited::class);

    $this->assertDatabaseCount('outbound_messages', 5);
    Queue::assertPushed(SendMessage::class, 5);
});

test('sending needs a tenant in context', function (): void {
    app(Messenger::class)->send(otp());
})->throws(RuntimeException::class);

test('the stored row is a tenant-owned outbound message', function (): void {
    $this->inTenant(fn () => app(Messenger::class)->send(otp()));

    $this->assertDatabaseHas('outbound_messages', ['tenant_id' => $this->tenant->id, 'to_phone' => '+201012345678', 'status' => 'queued', 'fallback_of_id' => null]);
    expect($this->inTenant(fn () => OutboundMessage::query()->count()))->toBe(1);
});

test('D70 an otp of a store with a connected number is logged on the store number', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());

    expect($this->inTenant(fn () => app(Messenger::class)->send(otp()))->provider)->toBe(Provider::WaGateway);
});
