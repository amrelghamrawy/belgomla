<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Services\Hypersender\HypersenderSmsDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    useHypersender();
    Http::preventStrayRequests();
    $this->message = $this->inTenant(fn () => OutboundMessage::factory()->sms()->create());
    $this->payload = new MessagePayload('+201012345678', MessagePurpose::Otp, 'رمز التحقق: 123456', Channel::Sms);
});

test('it is the hypersender provider', function (): void {
    expect(app(HypersenderSmsDriver::class)->provider())->toBe(Provider::Hypersender);
});

test('a send goes to the v1 sms api with the number, the text, our uuid and the sms token', function (): void {
    Http::fake(['app.hypersender.com/api/sms/v1/sms-instance/send-message' => Http::response(fixture('hypersender/sms-send'))]);

    $result = app(HypersenderSmsDriver::class)->send($this->message, $this->payload);

    expect([$result->ok, $result->final, $result->providerMessageId])->toBe([true, false, '9e064045-039b-4f94-a378-aa47b86a7e3f']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer sms-token')
        && $request->data() === ['to' => '+201012345678', 'content' => 'رمز التحقق: 123456', 'request_id' => $this->message->uuid]);
});

test('a refused sms send is a failure', function (): void {
    Http::fake(['*' => Http::response(['errors' => [], 'message' => 'The given data was invalid.', 'status' => '400'], 400)]);

    $result = app(HypersenderSmsDriver::class)->send($this->message, $this->payload);

    expect([$result->ok, $result->errorCode])->toBe([false, 'provider_rejected']);
});

test('status finds the message by our request id', function (string $fixture, bool $ok, ?string $errorCode): void {
    Http::fake(['app.hypersender.com/api/sms/v1/sms-instance/messages*' => Http::response(fixture('hypersender/'.$fixture))]);

    $result = app(HypersenderSmsDriver::class)->status($this->message);

    expect([$result->ok, $result->final, $result->errorCode])->toBe([$ok, true, $errorCode]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://app.hypersender.com/api/sms/v1/sms-instance/messages?'.http_build_query(['filter[request_id]' => $this->message->uuid]));
})->with([
    'delivered' => ['sms-messages-delivered', true, null],
    'failed' => ['sms-messages-failed', false, 'sms_failed'],
]);

test('status stays queued while the phone has not picked the message up', function (array $body, int $status): void {
    Http::fake(['*' => Http::response($body, $status)]);

    $result = app(HypersenderSmsDriver::class)->status($this->message);

    expect([$result->ok, $result->final])->toBe([true, false]);
})->with([
    'empty list' => [['data' => []], 200],
    'provider error' => [['message' => 'Server Error'], 500],
]);
