<?php

declare(strict_types=1);

use App\Modules\Messaging\Actions\AcceptHypersenderWebhook;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Jobs\ProcessProviderWebhook;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\SmsPhoneHeartbeat;
use App\Support\ApiException;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('a delivery status for a known message is queued for processing', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    app(AcceptHypersenderWebhook::class)->handle(Channel::WhatsApp, fixture('hypersender/webhook-message-ack'));

    Queue::assertPushedOn('messaging', ProcessProviderWebhook::class, fn (ProcessProviderWebhook $job): bool => $job->messageId === $message->id
        && $job->channel === Channel::WhatsApp
        && $job->payload === fixture('hypersender/webhook-message-ack'));
});

test('D49 an sms phone heartbeat is stored, not queued', function (): void {
    app(AcceptHypersenderWebhook::class)->handle(Channel::Sms, fixture('hypersender/webhook-heartbeat'));

    expect(SmsPhoneHeartbeat::query()->sole()->phone_id)->toBe('9defcf1e-552c-4bef-a843-872edec576ef');
    Queue::assertNothingPushed();
});

test('events that are not delivery statuses are acknowledged and dropped', function (): void {
    app(AcceptHypersenderWebhook::class)->handle(Channel::WhatsApp, ['event' => 'message.any', 'data' => ['payload' => ['id' => 'false_201@c.us_X']]]);

    Queue::assertNothingPushed();
    expect(SmsPhoneHeartbeat::query()->count())->toBe(0);
});

test('a status for a message we never sent is not found', function (): void {
    expect(fn () => app(AcceptHypersenderWebhook::class)->handle(Channel::Sms, fixture('hypersender/webhook-sms-delivered')))
        ->toThrow(function (ApiException $e): void {
            expect([$e->status, $e->errorCode])->toBe([404, 'not_found']);
        });

    Queue::assertNothingPushed();
});
