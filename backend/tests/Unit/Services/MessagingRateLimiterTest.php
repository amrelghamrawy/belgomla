<?php

declare(strict_types=1);

use App\Modules\Messaging\Exceptions\MessagingRateLimited;
use App\Modules\Messaging\Services\MessagingRateLimiter;

function sendsToPhone(int $count, string $phone = '+201012345678'): void
{
    foreach (range(1, $count) as $ignored) {
        app(MessagingRateLimiter::class)->ensurePhoneAllowed($phone);
    }
}

test('BR-CU07 five messages per phone per hour are allowed', function (): void {
    expect(fn () => $this->inTenant(fn () => sendsToPhone(5)))->not->toThrow(MessagingRateLimited::class);
});

test('BR-CU07 the sixth message to a phone within the hour is refused with the wait', function (): void {
    $this->freezeTime();
    $this->inTenant(fn () => sendsToPhone(5));

    expect(fn () => $this->inTenant(fn () => sendsToPhone(1)))
        ->toThrow(function (MessagingRateLimited $e): void {
            expect([$e->status, $e->details])->toBe([429, ['retry_after' => 3600]]);
        });
});

test('BR-CU07 the phone limit resets after an hour', function (): void {
    $this->inTenant(fn () => sendsToPhone(5));
    $this->travel(61)->minutes();

    expect(fn () => $this->inTenant(fn () => sendsToPhone(1)))->not->toThrow(MessagingRateLimited::class);
});

test('BR-CU07 the phone limit counts each phone separately', function (): void {
    $this->inTenant(fn () => sendsToPhone(5));

    expect(fn () => $this->inTenant(fn () => sendsToPhone(1, '+201112345678')))->not->toThrow(MessagingRateLimited::class);
});

test('BR-T04 the phone limit counts each tenant separately', function (): void {
    $other = $this->otherTenant();
    $this->inTenant(fn () => sendsToPhone(5));

    expect(fn () => $this->asTenant($other, fn () => sendsToPhone(1)))->not->toThrow(MessagingRateLimited::class);
});

test('D46 ten provider calls per minute go immediately', function (): void {
    $delays = array_map(fn (): ?int => app(MessagingRateLimiter::class)->providerDelay(), range(1, 10));

    expect(array_unique($delays))->toBe([null]);
});

test('D46 the eleventh provider call in a minute is delayed, not rejected', function (): void {
    $this->freezeTime();
    array_map(fn (): ?int => app(MessagingRateLimiter::class)->providerDelay(), range(1, 10));

    expect(app(MessagingRateLimiter::class)->providerDelay())->toBe(60);
});

test('D79 order status messages have their own bucket of ten per phone per hour and never use up the otp bucket', function (): void {
    $send = fn (int $count, App\Modules\Messaging\Enums\MessagePurpose $purpose) => $this->inTenant(function () use ($count, $purpose): void {
        foreach (range(1, $count) as $ignored) {
            app(MessagingRateLimiter::class)->ensurePhoneAllowed('+201012345678', $purpose);
        }
    });

    $send(10, App\Modules\Messaging\Enums\MessagePurpose::OrderStatus);

    expect(fn () => $send(1, App\Modules\Messaging\Enums\MessagePurpose::OrderStatus))->toThrow(MessagingRateLimited::class)
        ->and(fn () => $send(5, App\Modules\Messaging\Enums\MessagePurpose::Otp))->not->toThrow(MessagingRateLimited::class);
});
