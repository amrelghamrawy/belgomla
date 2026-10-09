<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Jobs\PollMessageStatus;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    useHypersender();
    Http::preventStrayRequests();
});

function polled(OutboundMessage $message): OutboundMessage
{
    return test()->inTenant(fn (): OutboundMessage => $message->fresh() ?? throw new RuntimeException('gone'));
}

test('a request that is still processing is polled again with the next delay', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued()->create());
    Http::fake(['*/queued-requests/*' => Http::response(fixture('hypersender/whatsapp-poll-pending'))]);

    runJob(new PollMessageStatus($message->id, 1));

    expect(polled($message)->status)->toBe(MessageStatus::Queued);
    Queue::assertPushedOn('messaging', PollMessageStatus::class, fn (PollMessageStatus $job): bool => $job->attempt === 2 && $job->delay === 10);
});

test('queued then polled then sent: the provider message id is stored and polling stops', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued()->create());
    Http::fake(['*/queued-requests/*' => Http::response(fixture('hypersender/whatsapp-poll-sent'))]);

    runJob(new PollMessageStatus($message->id, 2));

    expect([polled($message)->status, polled($message)->provider_message_id])->toBe([MessageStatus::Sent, '3EB0155CFDC2464F72FD75']);
    Queue::assertNothingPushed();
});

test('an sms poll that finds the message delivered finishes it', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->queued(null)->create());
    Http::fake(['*/messages*' => Http::response(fixture('hypersender/sms-messages-delivered'))]);

    runJob(new PollMessageStatus($message->id, 1));

    expect([polled($message)->status, polled($message)->body])->toBe([MessageStatus::Delivered, null]);
});

test('BR-CU07 a poll that finds the number not on whatsapp falls back to sms', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued()->create());
    Http::fake(['*/queued-requests/*' => Http::response(fixture('hypersender/whatsapp-poll-not-on-whatsapp'))]);

    runJob(new PollMessageStatus($message->id, 1));

    expect(polled($message)->status)->toBe(MessageStatus::FellBack);
    Queue::assertPushed(SendMessage::class, 1);
});

test('the last poll without a result fails the sms with poll_timeout', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->queued(null)->create());
    Http::fake(['*/messages*' => Http::response(['data' => []])]);

    runJob(new PollMessageStatus($message->id, 5));

    expect([polled($message)->status, polled($message)->error_code])->toBe([MessageStatus::Failed, 'poll_timeout']);
    Queue::assertNothingPushed();
});

test('BR-CU07 the last poll without a result moves a whatsapp message to sms', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued()->create());
    Http::fake(['*/queued-requests/*' => Http::response(fixture('hypersender/whatsapp-poll-pending'))]);

    runJob(new PollMessageStatus($message->id, 5));

    expect([polled($message)->status, polled($message)->error_code])->toBe([MessageStatus::FellBack, 'poll_timeout']);
    Queue::assertPushed(SendMessage::class, 1);
    Queue::assertNotPushed(PollMessageStatus::class);
});

test('a message finished by a webhook is not polled', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->delivered()->create());
    Http::fake();

    runJob(new PollMessageStatus($message->id, 1));

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('a status endpoint that keeps failing ends the message as unreachable', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->queued(null)->create());

    (new PollMessageStatus($message->id, 3))->failed(new ConnectionException('timeout'));

    expect([polled($message)->status, polled($message)->error_code])->toBe([MessageStatus::Failed, 'provider_unreachable']);
});

test('five polls with growing delays, on the messaging queue', function (): void {
    expect(PollMessageStatus::maxAttempts())->toBe(5)
        ->and(array_map(PollMessageStatus::delayBefore(...), [1, 2, 3, 4, 5, 6]))->toBe([5, 10, 20, 40, 60, 60])
        ->and((new PollMessageStatus(1, 1))->queue)->toBe('messaging')
        ->and((new PollMessageStatus(1, 1))->tries)->toBe(3);
});
