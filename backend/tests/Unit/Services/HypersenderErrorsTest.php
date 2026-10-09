<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Services\Hypersender\HypersenderErrors;

/**
 * @return list<mixed>
 */
function resultShape(DriverResult $result): array
{
    return [$result->ok, $result->providerRequestId, $result->providerMessageId, $result->errorCode, $result->notOnWhatsApp, $result->final, $result->delivered];
}

test('a whatsapp send returns the queued request uuid', function (): void {
    $result = (new HypersenderErrors)->fromWhatsAppSend(200, fixture('hypersender/whatsapp-queued'));

    expect(resultShape($result))->toBe([true, 'a0816120-7e37-4e8b-8cf3-92deb2cdc133', null, null, false, false, false]);
});

test('a whatsapp send refused by the provider is a failure with a stable code', function (int $status, array $body, string $code): void {
    $result = (new HypersenderErrors)->fromWhatsAppSend($status, $body);

    expect([$result->ok, $result->errorCode, $result->notOnWhatsApp, $result->final])->toBe([false, $code, false, true]);
})->with([
    'bad token' => [401, ['message' => 'Unauthenticated.'], 'provider_unauthorized'],
    'unknown instance' => [404, ['message' => 'Resource not found.'], 'provider_instance_not_found'],
    'D46 meta capped the instance' => [423, ['message' => 'Meta has capped this instance'], 'whatsapp_capped'],
    'rate limited' => [429, ['message' => 'Too Many Attempts.'], 'provider_rate_limited'],
    'validation' => [422, ['message' => 'The given data was invalid.', 'errors' => ['chatId' => ['required']]], 'provider_rejected'],
    'server error' => [500, ['message' => 'Server Error'], 'provider_error'],
    '2xx without a queued uuid' => [200, [], 'provider_error'],
]);

test('BR-CU07 a send rejected because the number is not on whatsapp says so', function (): void {
    $body = fixture('hypersender/whatsapp-poll-not-on-whatsapp')['response_body'];

    $result = (new HypersenderErrors)->fromWhatsAppSend(422, is_array($body) ? $body : []);

    expect([$result->errorCode, $result->notOnWhatsApp])->toBe(['not_on_whatsapp', true]);
});

test('queued requests are classified from their response status', function (string $fixture, array $expected): void {
    expect(resultShape((new HypersenderErrors)->fromQueuedRequest(fixture('hypersender/'.$fixture))))->toBe($expected);
})->with([
    'still processing' => ['whatsapp-poll-pending', [true, 'a0816120-7e37-4e8b-8cf3-92deb2cdc133', null, null, false, false, false]],
    'sent, key.id' => ['whatsapp-poll-sent', [true, 'a0816120-7e37-4e8b-8cf3-92deb2cdc133', '3EB0155CFDC2464F72FD75', null, false, true, false]],
    'sent, legacy message_id' => ['whatsapp-poll-sent-legacy', [true, 'a0816120-7e37-4e8b-8cf3-92deb2cdc133', 'AAAAAA0000BBBBB00CCCCCC', null, false, true, false]],
    'BR-CU07 not on whatsapp' => ['whatsapp-poll-not-on-whatsapp', [false, 'a0816120-7e37-4e8b-8cf3-92deb2cdc133', null, 'not_on_whatsapp', true, true, false]],
    'server error' => ['whatsapp-poll-error-500', [false, 'a0816120-7e37-4e8b-8cf3-92deb2cdc133', null, 'provider_error', false, true, false]],
]);

test('BR-CU07 a 404 inside a queued request means the recipient is not on whatsapp', function (): void {
    $result = (new HypersenderErrors)->fromQueuedRequest(['uuid' => 'req-1', 'response_status' => 404, 'response_body' => ['message' => 'Not found']]);

    expect([$result->errorCode, $result->notOnWhatsApp])->toBe(['not_on_whatsapp', true]);
});

test('BR-CU07 the not-on-whatsapp message is recognised without the exists flag', function (): void {
    $result = (new HypersenderErrors)->fromQueuedRequest(['uuid' => 'req-1', 'response_status' => 422, 'response_body' => [
        'errors' => ['messages' => ['The number you want to send the message to is NOT on WhatsApp.']],
    ]]);

    expect($result->notOnWhatsApp)->toBeTrue();
});

test('sms messages are classified from their status', function (array $message, array $expected): void {
    expect(resultShape((new HypersenderErrors)->fromSmsMessage($message)))->toBe($expected);
})->with([
    'scheduled on the phone' => [['id' => 'sms-1', 'request_id' => 'req-1', 'status' => 'scheduled'], [true, 'req-1', 'sms-1', null, false, false, false]],
    'sent' => [['id' => 'sms-1', 'request_id' => 'req-1', 'status' => 'sent'], [true, 'req-1', 'sms-1', null, false, true, false]],
    'delivered' => [['id' => 'sms-1', 'request_id' => 'req-1', 'status' => 'delivered'], [true, null, 'sms-1', null, false, true, true]],
    'failed' => [['id' => 'sms-1', 'request_id' => 'req-1', 'status' => 'failed'], [false, 'req-1', null, 'sms_failed', false, true, false]],
    'expired' => [['id' => 'sms-1', 'request_id' => 'req-1', 'status' => 'expired'], [false, 'req-1', null, 'sms_expired', false, true, false]],
    'unknown status' => [['id' => 7, 'status' => null], [true, null, '7', null, false, false, false]],
]);

test('an sms api http error is a failure with a stable code', function (): void {
    expect((new HypersenderErrors)->fromSmsHttpError(400)->errorCode)->toBe('provider_rejected');
});

test('webhook references point at the message a status is about', function (Channel $channel, string $fixture, ?array $expected): void {
    expect((new HypersenderErrors)->webhookReference($channel, fixture('hypersender/'.$fixture)))->toBe($expected);
})->with([
    'whatsapp ack' => [Channel::WhatsApp, 'webhook-message-ack', ['message_id' => '3EB0155CFDC2464F72FD75', 'request_id' => null]],
    'sms delivered' => [Channel::Sms, 'webhook-sms-delivered', ['message_id' => '9e064045-039b-4f94-a378-aa47b86a7e3f', 'request_id' => '133554b5-ae44-44a0-8f4f-7bbac5657a11']],
    'sms failed' => [Channel::Sms, 'webhook-sms-failed', ['message_id' => '9e064045-039b-4f94-a378-aa47b86a7e3f', 'request_id' => '133554b5-ae44-44a0-8f4f-7bbac5657a11']],
    'heartbeat is ignored' => [Channel::Sms, 'webhook-heartbeat', null],
    'an sms event on the whatsapp route is ignored' => [Channel::WhatsApp, 'webhook-sms-delivered', null],
]);

test('webhook events we do not track are ignored', function (Channel $channel, array $payload): void {
    expect((new HypersenderErrors)->webhookReference($channel, $payload))->toBeNull();
})->with([
    'incoming whatsapp message' => [Channel::WhatsApp, ['event' => 'message.any', 'data' => ['payload' => ['id' => 'false_201@c.us_X']]]],
    'ack without a message id' => [Channel::WhatsApp, ['event' => 'message.ack', 'data' => ['payload' => ['ack' => 2]]]],
    'sms status without ids' => [Channel::Sms, ['event' => 'message.phone.sent', 'data' => ['status' => 'sent']]],
]);

test('whatsapp acks map to sent, delivered and failed', function (int $ack, array $expected): void {
    $payload = fixture('hypersender/webhook-message-ack');
    $payload['data']['payload']['ack'] = $ack;

    expect(resultShape((new HypersenderErrors)->fromWebhook(Channel::WhatsApp, $payload)))->toBe($expected);
})->with([
    'ERROR' => [-1, [false, null, null, 'whatsapp_ack_error', false, true, false]],
    'PENDING' => [0, [true, null, '3EB0155CFDC2464F72FD75', null, false, true, false]],
    'SERVER' => [1, [true, null, '3EB0155CFDC2464F72FD75', null, false, true, false]],
    'DEVICE' => [2, [true, null, '3EB0155CFDC2464F72FD75', null, false, true, true]],
    'READ' => [3, [true, null, '3EB0155CFDC2464F72FD75', null, false, true, true]],
]);

test('sms webhooks reuse the sms message classification', function (): void {
    $result = (new HypersenderErrors)->fromWebhook(Channel::Sms, fixture('hypersender/webhook-sms-failed'));

    expect([$result->ok, $result->errorCode])->toBe([false, 'sms_failed']);
});

test('the whatsapp key id is the part after the last underscore of a serialized id', function (string $serialized, string $keyId): void {
    expect(HypersenderErrors::whatsAppKeyId($serialized))->toBe($keyId);
})->with([
    'serialized' => ['true_201012345678@c.us_3EB0155CFDC2464F72FD75', '3EB0155CFDC2464F72FD75'],
    'already a key id' => ['3EB0155CFDC2464F72FD75', '3EB0155CFDC2464F72FD75'],
]);
