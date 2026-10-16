<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Jobs\PollMessageStatus;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Services\MessagingRateLimiter;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    useHypersender();
    Http::preventStrayRequests();
});

function messageRow(OutboundMessage $message): OutboundMessage
{
    return test()->inTenant(fn (): OutboundMessage => $message->fresh() ?? throw new RuntimeException('gone'));
}

test('an accepted whatsapp send is stored as queued and its first poll is scheduled', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());
    Http::fake(['*/send-text-safe' => Http::response(fixture('hypersender/whatsapp-queued'))]);

    runJob(new SendMessage($message->id));

    expect([messageRow($message)->status, messageRow($message)->provider_request_id])->toBe([MessageStatus::Queued, 'a0816120-7e37-4e8b-8cf3-92deb2cdc133']);
    Queue::assertPushedOn('messaging', PollMessageStatus::class, fn (PollMessageStatus $job): bool => $job->messageId === $message->id
        && $job->attempt === 1
        && $job->delay === 5);
});

test('BR-CU07 whatsapp refused at send time creates the sms row with fallback_of_id and queues it', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());
    Http::fake(['*/send-text-safe' => Http::response(fixture('hypersender/whatsapp-poll-not-on-whatsapp')['response_body'], 422)]);

    runJob(new SendMessage($message->id));

    $sms = $this->inTenant(fn () => OutboundMessage::query()->where('fallback_of_id', $message->id)->firstOrFail());
    expect([messageRow($message)->status, messageRow($message)->error_code])->toBe([MessageStatus::FellBack, 'not_on_whatsapp'])
        ->and($sms->status)->toBe(MessageStatus::Queued);
    Queue::assertPushed(SendMessage::class, fn (SendMessage $job): bool => $job->messageId === $sms->id);
    Queue::assertNotPushed(PollMessageStatus::class);
});

test('BR-CU07 a failed sms is marked failed and nothing else is sent', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->create());
    Http::fake(['*/send-message' => Http::response(['message' => 'Resource not found.'], 404)]);

    runJob(new SendMessage($message->id));

    expect([messageRow($message)->status, messageRow($message)->error_code])->toBe([MessageStatus::Failed, 'provider_instance_not_found']);
    $this->assertDatabaseCount('outbound_messages', 1);
    Queue::assertNothingPushed();
});

test('an sms the phone has not sent yet is polled', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->create());
    Http::fake(['*/send-message' => Http::response(fixture('hypersender/sms-send'))]);

    runJob(new SendMessage($message->id));

    expect([messageRow($message)->status, messageRow($message)->provider_message_id])->toBe([MessageStatus::Queued, '9e064045-039b-4f94-a378-aa47b86a7e3f']);
    Queue::assertPushed(PollMessageStatus::class, 1);
});

test('D46 when the provider limit is reached the job waits instead of failing', function (): void {
    $this->freezeTime();
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());
    array_map(fn (): ?int => app(MessagingRateLimiter::class)->providerDelay(), range(1, 10));
    Http::fake();

    $job = (new SendMessage($message->id))->withFakeQueueInteractions();
    app()->call($job->handle(...));

    $job->assertReleased(60);
    Http::assertNothingSent();
    expect(messageRow($message)->status)->toBe(MessageStatus::Queued);
});

test('the log driver is not throttled by the provider limit', function (): void {
    config(['messaging.driver' => 'log']);
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());
    array_map(fn (): ?int => app(MessagingRateLimiter::class)->providerDelay(), range(1, 10));

    runJob(new SendMessage($message->id));

    expect([messageRow($message)->status, messageRow($message)->provider_message_id])->toBe([MessageStatus::Sent, 'log-'.$message->uuid]);
});

test('the driver runs inside the tenant of the message, and the context is restored afterwards', function (): void {
    $other = $this->otherTenant();
    $message = $this->asTenant($other, fn () => OutboundMessage::factory()->create());
    $tenantDuringSend = null;
    Http::fake(function () use (&$tenantDuringSend) {
        $tenantDuringSend = app(TenantContext::class)->idOrNull();

        return Http::response(fixture('hypersender/whatsapp-queued'));
    });

    runJob(new SendMessage($message->id));

    expect($tenantDuringSend)->toBe($other->id)
        ->and(app(TenantContext::class)->has())->toBeFalse();
});

test('a message that already reached the provider is not sent twice', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued('req-1')->create());
    Http::fake();

    runJob(new SendMessage($message->id));

    Http::assertNothingSent();
});

test('a provider that stays unreachable fails the whatsapp message over to sms', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());

    (new SendMessage($message->id))->failed(new ConnectionException('timeout'));

    expect([messageRow($message)->status, messageRow($message)->error_code])->toBe([MessageStatus::FellBack, 'provider_unreachable']);
    $this->assertDatabaseHas('outbound_messages', ['fallback_of_id' => $message->id]);
});

test('the job runs on the messaging queue and retries for ten minutes with backoff', function (): void {
    $this->freezeTime();
    $job = new SendMessage(1);

    expect($job->queue)->toBe('messaging')
        ->and($job->backoff())->toBe([10, 30, 60])
        ->and($job->retryUntil()->getTimestamp())->toBe(now()->addMinutes(10)->getTimestamp());
});

test('D70 a store number message is sent through the gateway and is final', function (): void {
    useWaGateway();
    $connection = $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));
    Http::fake(["127.0.0.1:3100/sessions/{$connection->session_key}/messages" => Http::response(['id' => '3EB0ABC'])]);

    runJob(new SendMessage($message->id));

    expect([messageRow($message)->status, messageRow($message)->provider_message_id])->toBe([MessageStatus::Sent, '3EB0ABC']);
    Queue::assertNotPushed(PollMessageStatus::class);
});

test('D70 a store number message is not held by the platform provider limit', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));
    array_map(fn (): ?int => app(MessagingRateLimiter::class)->providerDelay(), range(1, 10));
    Http::fake(['127.0.0.1:3100/*' => Http::response(['id' => '3EB0ABC'])]);

    runJob(new SendMessage($message->id));

    expect(messageRow($message)->status)->toBe(MessageStatus::Sent);
});

test('D70 over the store number limit the job waits as long as the gateway says', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));
    Http::fake(['127.0.0.1:3100/*' => Http::response(['error' => 'rate_limited', 'retry_after' => 42], 429)]);

    $job = (new SendMessage($message->id))->withFakeQueueInteractions();
    app()->call($job->handle(...));

    $job->assertReleased(42);
    expect([messageRow($message)->status, messageRow($message)->error_code])->toBe([MessageStatus::Queued, null]);
});

test('D70 a dropped store number hands the message to the platform number at once', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));
    Http::fake(['127.0.0.1:3100/*' => Http::response(['error' => 'not_open'], 409)]);

    runJob(new SendMessage($message->id));

    expect([messageRow($message)->status, messageRow($message)->error_code])->toBe([MessageStatus::FellBack, 'session_not_open']);
    $this->assertDatabaseHas('outbound_messages', ['fallback_of_id' => $message->id, 'channel' => 'whatsapp', 'provider' => 'hypersender']);
});
