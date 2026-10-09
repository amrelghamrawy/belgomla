<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessageStatus;
use App\Modules\Messaging\Jobs\ProcessProviderWebhook;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('a delivered ack finishes the whatsapp message', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    runJob(new ProcessProviderWebhook($message->id, Channel::WhatsApp, fixture('hypersender/webhook-message-ack')));

    expect($this->inTenant(fn () => $message->fresh()?->status))->toBe(MessageStatus::Delivered);
});

test('BR-CU07 an error ack moves the whatsapp message to sms', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    runJob(new ProcessProviderWebhook($message->id, Channel::WhatsApp, fixture('hypersender/webhook-message-ack-error')));

    expect($this->inTenant(fn () => $message->fresh()?->status))->toBe(MessageStatus::FellBack);
    Queue::assertPushed(SendMessage::class, 1);
});

test('an sms failure webhook fails the sms', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->sent('9e064045-039b-4f94-a378-aa47b86a7e3f')->create());

    runJob(new ProcessProviderWebhook($message->id, Channel::Sms, fixture('hypersender/webhook-sms-failed')));

    expect($this->inTenant(fn (): array => [$message->fresh()?->status, $message->fresh()?->error_code]))->toBe([MessageStatus::Failed, 'sms_failed']);
});

test('the webhook job runs on the messaging queue', function (): void {
    expect((new ProcessProviderWebhook(1, Channel::Sms, []))->queue)->toBe('messaging');
});
