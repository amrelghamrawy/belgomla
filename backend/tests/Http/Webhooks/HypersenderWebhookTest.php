<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Jobs\ProcessProviderWebhook;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
| POST /api/webhooks/hypersender/{whatsapp|sms} on hooks.{base_domain} (08-api-contract.md §13).
| QUEUE_CONNECTION=sync in tests, so the ProcessProviderWebhook job runs inside the request.
*/

/**
 * @param  array<array-key, mixed>  $payload
 * @return TestResponse<Symfony\Component\HttpFoundation\Response>
 */
function hook(string $channel, array $payload, string $secret = 'test-webhook-secret', string $host = 'hooks.gomal.test'): TestResponse
{
    app(TenantContext::class)->clear();

    return test()->postJson("http://{$host}/api/webhooks/hypersender/{$channel}", $payload, ['Authorization' => $secret]);
}

function statusOf(OutboundMessage $message): MessageStatus
{
    return test()->inTenant(fn (): MessageStatus => $message->fresh()->status ?? throw new RuntimeException('gone'));
}

test('BR-CU07 a whatsapp delivery ack with a valid secret marks the message delivered', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    hook('whatsapp', fixture('hypersender/webhook-message-ack'))
        ->assertOk()
        ->assertExactJson(['data' => ['received' => true]]);

    expect(statusOf($message))->toBe(MessageStatus::Delivered);
    $this->assertDatabaseHas('outbound_messages', ['id' => $message->id, 'status' => 'delivered', 'body' => null]);
});

test('an sms delivery report finds the message by our request id', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->create(['uuid' => '133554b5-ae44-44a0-8f4f-7bbac5657a11']));

    hook('sms', fixture('hypersender/webhook-sms-delivered'))->assertOk();

    expect(statusOf($message))->toBe(MessageStatus::Delivered);
});

test('BR-CU07 a whatsapp error ack enqueues the sms fallback', function (): void {
    Queue::fake()->except(ProcessProviderWebhook::class);
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    hook('whatsapp', fixture('hypersender/webhook-message-ack-error'))->assertOk();

    expect(statusOf($message))->toBe(MessageStatus::FellBack);
    $this->assertDatabaseHas('outbound_messages', ['fallback_of_id' => $message->id, 'channel' => 'sms', 'status' => 'queued']);
    Queue::assertPushed(SendMessage::class, 1);
});

test('a wrong secret is 401 and changes nothing', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    hook('whatsapp', fixture('hypersender/webhook-message-ack'), 'wrong')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    expect(statusOf($message))->toBe(MessageStatus::Sent);
});

test('a replayed webhook is idempotent', function (): void {
    Queue::fake()->except(ProcessProviderWebhook::class);
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    hook('whatsapp', fixture('hypersender/webhook-message-ack-error'))->assertOk();
    hook('whatsapp', fixture('hypersender/webhook-message-ack-error'))->assertOk();

    expect(statusOf($message))->toBe(MessageStatus::FellBack);
    $this->assertDatabaseCount('outbound_messages', 2);
    Queue::assertPushed(SendMessage::class, 1);
});

test('a status for an unknown message is 404 json', function (): void {
    hook('sms', fixture('hypersender/webhook-sms-delivered'))
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'العنصر غير موجود']]);
});

test('events that are not delivery statuses are acknowledged', function (): void {
    hook('sms', fixture('hypersender/webhook-heartbeat'))
        ->assertOk()
        ->assertExactJson(['data' => ['received' => true]]);
});

test('a body without event and data is a validation error', function (array $payload, string $field): void {
    hook('sms', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'no event' => [['data' => ['id' => 'x']], 'event'],
    'data is not an object' => [['event' => 'message.phone.sent', 'data' => 'x'], 'data'],
]);

test('an unknown channel is not routed', function (): void {
    hook('telegram', fixture('hypersender/webhook-sms-delivered'))->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

test('the webhook only exists on the hooks host, never on a tenant host', function (): void {
    $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    hook('whatsapp', fixture('hypersender/webhook-message-ack'), host: 'alnoor.gomal.test')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('BR-T04 a webhook changes only the message it names, in its own tenant', function (): void {
    $other = $this->otherTenant();
    $mine = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());
    $theirs = $this->asTenant($other, fn () => OutboundMessage::factory()->sent('OTHER-KEY')->create());

    hook('whatsapp', fixture('hypersender/webhook-message-ack'))->assertOk();

    expect(statusOf($mine))->toBe(MessageStatus::Delivered);
    $this->assertDatabaseHas('outbound_messages', ['id' => $theirs->id, 'tenant_id' => $other->id, 'status' => 'sent']);
});
