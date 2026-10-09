<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->freezeTime();
    $this->makeCustomer(['phone' => '+201012345678', 'status' => CustomerStatus::PendingVerification, 'phone_verified_at' => null]);
    $this->tenantJson('POST', '/api/store/auth/resend-otp', ['phone' => '01012345678', 'purpose' => 'register'])->assertOk();
});

function resend(array $body = []): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/store/auth/resend-otp', [...['phone' => '01012345678', 'purpose' => 'register'], ...$body]);
}

test('BR-CU07 a new code replaces the previous one', function (): void {
    resend()->assertOk()->assertExactJson(['data' => ['otp_sent_via' => 'whatsapp']]);

    $codes = $this->inTenant(fn () => CustomerOtp::query()->orderBy('id')->get());

    expect($codes)->toHaveCount(2)
        ->and($codes[0]->consumed_at)->not->toBeNull()
        ->and($codes[1]->consumed_at)->toBeNull();
});

test('BR-CU07 send by sms instead is refused within 60 seconds of the last code', function (): void {
    $this->travel(59)->seconds();

    resend(['channel' => 'sms'])
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.details.retry_after', 1);
});

test('BR-CU07 send by sms instead is allowed after 60 seconds and goes over sms', function (): void {
    $this->travel(60)->seconds();

    resend(['channel' => 'sms'])
        ->assertOk()
        ->assertExactJson(['data' => ['otp_sent_via' => 'sms']]);

    expect($this->inTenant(fn () => OutboundMessage::query()->latest('id')->first()?->channel))->toBe(Channel::Sms)
        ->and($this->inTenant(fn () => CustomerOtp::query()->latest('id')->first()?->channel))->toBe('sms');
});

test('D52 an unknown phone gets the same answer and no message', function (): void {
    resend(['phone' => '01112345678'])
        ->assertOk()
        ->assertExactJson(['data' => ['otp_sent_via' => 'whatsapp']]);

    expect($this->inTenant(fn () => OutboundMessage::query()->count()))->toBe(1);
});

test('BR-CU07 at most five codes per phone per hour', function (): void {
    resend()->assertOk();
    resend()->assertOk();
    resend()->assertOk();
    resend()->assertOk();

    resend()
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');

    $this->travel(61)->minutes();
    resend()->assertOk();
});

test('resend validation', function (array $body, string $field): void {
    resend($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'invalid phone' => [['phone' => 'abc'], 'phone'],
    'unknown purpose' => [['purpose' => 'x'], 'purpose'],
    'unknown channel' => [['channel' => 'email'], 'channel'],
]);
