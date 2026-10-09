<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Queries\OutboundMessageByProviderIdQuery;

test('a webhook message id finds its message without a tenant in context', function (): void {
    $other = $this->otherTenant();
    $message = $this->asTenant($other, fn () => OutboundMessage::factory()->sent('3EB0155CFDC2464F72FD75')->create());

    $found = app(OutboundMessageByProviderIdQuery::class)->find(Channel::WhatsApp, '3EB0155CFDC2464F72FD75', null);

    expect($found?->id)->toBe($message->id)
        ->and($found?->tenant_id)->toBe($other->id);
});

test('a provider request id finds its message', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->create(['provider_request_id' => 'req-77']));

    expect(app(OutboundMessageByProviderIdQuery::class)->find(Channel::Sms, null, 'req-77')?->id)->toBe($message->id);
});

test('the sms request id we sent (the row uuid) finds its message', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->create());

    expect(app(OutboundMessageByProviderIdQuery::class)->find(Channel::Sms, 'unknown-sms-id', $message->uuid)?->id)->toBe($message->id);
});

test('the lookup is limited to the channel of the webhook', function (): void {
    $this->inTenant(fn () => OutboundMessage::factory()->sms()->sent('shared-id')->create());

    expect(app(OutboundMessageByProviderIdQuery::class)->find(Channel::WhatsApp, 'shared-id', null))->toBeNull();
});

test('nothing is found without ids or for a non-uuid request id', function (?string $messageId, ?string $requestId): void {
    $this->inTenant(fn () => OutboundMessage::factory()->sms()->create());

    expect(app(OutboundMessageByProviderIdQuery::class)->find(Channel::Sms, $messageId, $requestId))->toBeNull();
})->with([
    'no ids' => [null, null],
    'request id that is not a uuid' => [null, 'not-a-uuid'],
]);
