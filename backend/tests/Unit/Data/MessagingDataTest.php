<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;

test('BR-CU07 a payload goes by whatsapp unless sms is asked for', function (): void {
    $payload = new MessagePayload('01012345678', MessagePurpose::Otp, 'رمز التحقق: 123456');

    expect($payload->channel)->toBe(Channel::WhatsApp);
});

test('a payload is rebuilt from a stored message, body included', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->create(['purpose' => MessagePurpose::Invitation, 'body' => 'رابط الدعوة'])->fresh());

    $payload = MessagePayload::fromMessage($message);

    expect([$payload->toPhone, $payload->purpose, $payload->text, $payload->channel])
        ->toBe(['+201012345678', MessagePurpose::Invitation, 'رابط الدعوة', Channel::Sms]);
});

test('driver results carry what each outcome means', function (DriverResult $result, array $expected): void {
    expect([$result->ok, $result->providerRequestId, $result->providerMessageId, $result->errorCode, $result->notOnWhatsApp, $result->final, $result->delivered])
        ->toBe($expected);
})->with([
    'queued' => [DriverResult::queued('req-1'), [true, 'req-1', null, null, false, false, false]],
    'queued with a message id' => [DriverResult::queued('req-1', 'msg-1'), [true, 'req-1', 'msg-1', null, false, false, false]],
    'sent' => [DriverResult::sent('msg-1', 'req-1'), [true, 'req-1', 'msg-1', null, false, true, false]],
    'delivered' => [DriverResult::delivered('msg-1'), [true, null, 'msg-1', null, false, true, true]],
    'failed' => [DriverResult::failed('provider_error'), [false, null, null, 'provider_error', false, true, false]],
    'not on whatsapp' => [DriverResult::failed('not_on_whatsapp', true, 'req-1'), [false, 'req-1', null, 'not_on_whatsapp', true, true, false]],
]);
