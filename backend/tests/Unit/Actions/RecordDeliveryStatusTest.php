<?php

declare(strict_types=1);

use App\Modules\Messaging\Actions\RecordDeliveryStatus;
use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

function record(OutboundMessage $message, DriverResult $result): OutboundMessage
{
    return test()->inTenant(fn () => app(RecordDeliveryStatus::class)->handle($message, $result));
}

test('an accepted send keeps the message queued with the provider request id', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());

    $recorded = record($message, DriverResult::queued('req-1'));

    expect([$recorded->status, $recorded->provider_request_id, $recorded->body])->toBe([MessageStatus::Queued, 'req-1', 'رمز التحقق: 123456']);
});

test('a sent result stores the provider message id and keeps the request id', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued('req-1')->create());

    $recorded = record($message, DriverResult::sent('msg-1'));

    expect([$recorded->status, $recorded->provider_request_id, $recorded->provider_message_id])->toBe([MessageStatus::Sent, 'req-1', 'msg-1']);
});

test('a delivered message is final and its body is cleared', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('msg-1')->create());

    $recorded = record($message, DriverResult::delivered('msg-1'));

    expect([$recorded->status, $recorded->body])->toBe([MessageStatus::Delivered, null]);
    $this->assertDatabaseHas('outbound_messages', ['id' => $message->id, 'status' => 'delivered', 'body' => null]);
});

test('a sent sms drops its body, a sent whatsapp message keeps it for a possible fallback', function (string $state, ?string $body): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->{$state}()->create());

    expect(record($message, DriverResult::sent('msg-1'))->body)->toBe($body);
})->with([
    'sms' => ['sms', null],
    'whatsapp' => ['whatsapp', 'رمز التحقق: 123456'],
]);

test('BR-CU07 a whatsapp message to a number not on whatsapp falls back to an sms copy', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued('req-1')->create(['purpose' => MessagePurpose::Invitation, 'body' => 'رابط الدعوة']));

    $recorded = record($message, DriverResult::failed('not_on_whatsapp', true));

    $sms = $this->inTenant(fn () => OutboundMessage::query()->where('fallback_of_id', $message->id)->firstOrFail());
    expect([$recorded->status, $recorded->error_code, $recorded->body])->toBe([MessageStatus::FellBack, 'not_on_whatsapp', null])
        ->and([$sms->channel, $sms->status, $sms->to_phone, $sms->purpose, $sms->body, $sms->tenant_id])
        ->toBe([Channel::Sms, MessageStatus::Queued, '+201012345678', MessagePurpose::Invitation, 'رابط الدعوة', $this->tenant->id])
        ->and($sms->uuid)->not->toBe($message->uuid);
    Queue::assertPushed(SendMessage::class, fn (SendMessage $job): bool => $job->messageId === $sms->id);
});

test('BR-CU07 any whatsapp send failure falls back to sms', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());

    expect(record($message, DriverResult::failed('provider_error'))->status)->toBe(MessageStatus::FellBack);
    $this->assertDatabaseHas('outbound_messages', ['fallback_of_id' => $message->id, 'channel' => 'sms']);
});

test('BR-CU07 a failed sms is final and never loops', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->create());

    $recorded = record($message, DriverResult::failed('sms_failed'));

    expect([$recorded->status, $recorded->error_code, $recorded->body])->toBe([MessageStatus::Failed, 'sms_failed', null]);
    $this->assertDatabaseCount('outbound_messages', 1);
    Queue::assertNothingPushed();
});

test('a final message ignores every later result, so replays change nothing', function (string $state, DriverResult $result): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->{$state}()->create());

    $recorded = record($message, $result);

    expect($recorded->status)->toBe($message->status)
        ->and($recorded->updated_at->equalTo($message->updated_at))->toBeTrue();
    $this->assertDatabaseCount('outbound_messages', 1);
    Queue::assertNothingPushed();
})->with([
    'delivered then failed' => ['delivered', DriverResult::failed('whatsapp_ack_error')],
    'failed then delivered' => ['failed', DriverResult::delivered('msg-1')],
    'fell back then failed again' => ['fellBack', DriverResult::failed('not_on_whatsapp', true)],
]);

test('the action reads the row under lock, so a stale copy cannot undo a newer status', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create());
    record($message, DriverResult::delivered('msg-1'));

    $recorded = record($message, DriverResult::sent('msg-1'));

    expect($recorded->status)->toBe(MessageStatus::Delivered);
});

test('D70 a failed store number message is re-sent from the platform number', function (): void {
    useHypersender();
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));

    $recorded = record($message, DriverResult::failed('session_not_open'));

    $copy = $this->inTenant(fn () => OutboundMessage::query()->where('fallback_of_id', $message->id)->firstOrFail());
    expect([$recorded->status, $recorded->body])->toBe([MessageStatus::FellBack, null])
        ->and([$copy->channel, $copy->provider, $copy->status, $copy->body])
        ->toBe([Channel::WhatsApp, Provider::Hypersender, MessageStatus::Queued, 'رمز التحقق: 123456']);
    Queue::assertPushed(SendMessage::class, fn (SendMessage $job): bool => $job->messageId === $copy->id);
});

test('D70 a store number message to a number not on whatsapp goes straight to sms', function (): void {
    useHypersender();
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));

    record($message, DriverResult::failed('not_on_whatsapp', true));

    $this->assertDatabaseHas('outbound_messages', ['fallback_of_id' => $message->id, 'channel' => 'sms', 'provider' => 'hypersender']);
});

test('BR-CU07 D70 the chain store number, platform number, sms makes one copy per step', function (): void {
    useHypersender();
    $store = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));

    record($store, DriverResult::failed('gateway_unreachable'));
    $platform = $this->inTenant(fn () => OutboundMessage::query()->where('fallback_of_id', $store->id)->firstOrFail());
    record($platform, DriverResult::failed('provider_error'));
    $sms = $this->inTenant(fn () => OutboundMessage::query()->where('fallback_of_id', $platform->id)->firstOrFail());
    record($sms, DriverResult::failed('sms_failed'));

    expect([$platform->channel, $platform->provider, $sms->channel])->toBe([Channel::WhatsApp, Provider::Hypersender, Channel::Sms]);
    $this->assertDatabaseCount('outbound_messages', 3);
});

test('D70 a replayed store number failure makes no second copy', function (): void {
    useHypersender();
    $message = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider' => Provider::WaGateway]));

    record($message, DriverResult::failed('session_not_open'));
    record($message, DriverResult::failed('session_not_open'));

    $this->assertDatabaseCount('outbound_messages', 2);
});
