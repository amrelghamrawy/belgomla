<?php

declare(strict_types=1);

use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Services\Hypersender\HypersenderWhatsAppDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    useHypersender();
    Http::preventStrayRequests();
    $this->message = $this->inTenant(fn () => OutboundMessage::factory()->create(['provider_request_id' => null]));
    $this->payload = new MessagePayload('+201012345678', MessagePurpose::Otp, 'رمز التحقق: 123456');
});

test('it is the hypersender provider', function (): void {
    expect(app(HypersenderWhatsAppDriver::class)->provider())->toBe(Provider::Hypersender);
});

test('D46 a send goes to send-text-safe with the chat id, the text and the instance token', function (): void {
    Http::fake(['app.hypersender.com/api/whatsapp/v2/wa-instance/send-text-safe' => Http::response(fixture('hypersender/whatsapp-queued'))]);

    $result = app(HypersenderWhatsAppDriver::class)->send($this->message, $this->payload);

    expect([$result->ok, $result->final, $result->providerRequestId])->toBe([true, false, 'a0816120-7e37-4e8b-8cf3-92deb2cdc133']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://app.hypersender.com/api/whatsapp/v2/wa-instance/send-text-safe'
        && $request->hasHeader('Authorization', 'Bearer wa-token')
        && $request->data() === ['chatId' => '201012345678@c.us', 'text' => 'رمز التحقق: 123456']);
});

test('plain send-text is used when safe send is off', function (): void {
    config(['messaging.hypersender.safe_send' => false]);
    Http::fake(['app.hypersender.com/api/whatsapp/v2/wa-instance/send-text' => Http::response(fixture('hypersender/whatsapp-queued'))]);

    app(HypersenderWhatsAppDriver::class)->send($this->message, $this->payload);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/wa-instance/send-text'));
});

test('a refused send is a failure', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $result = app(HypersenderWhatsAppDriver::class)->send($this->message, $this->payload);

    expect([$result->ok, $result->errorCode])->toBe([false, 'provider_unauthorized']);
});

test('status reads the queued request of the message', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued()->create());
    Http::fake(['app.hypersender.com/api/whatsapp/v2/wa-instance/queued-requests/a0816120-7e37-4e8b-8cf3-92deb2cdc133' => Http::response(fixture('hypersender/whatsapp-poll-sent'))]);

    $result = app(HypersenderWhatsAppDriver::class)->status($message);

    expect([$result->ok, $result->final, $result->providerMessageId])->toBe([true, true, '3EB0155CFDC2464F72FD75']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer wa-token'));
});

test('status of a message without a request id is a failure, without calling the provider', function (): void {
    Http::fake();

    expect(app(HypersenderWhatsAppDriver::class)->status($this->message)->errorCode)->toBe('missing_request_id');
    Http::assertNothingSent();
});

test('an unknown queued request is a failure', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued()->create());
    Http::fake(['*' => Http::response(['message' => 'Resource not found.'], 404)]);

    expect(app(HypersenderWhatsAppDriver::class)->status($message)->errorCode)->toBe('request_not_found');
});

test('a provider error while polling keeps the message queued for the next poll', function (): void {
    $message = $this->inTenant(fn () => OutboundMessage::factory()->queued()->create());
    Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

    $result = app(HypersenderWhatsAppDriver::class)->status($message);

    expect([$result->ok, $result->final])->toBe([true, false]);
});
