<?php

declare(strict_types=1);

use App\Modules\Customers\Services\OtpCodes;

test('BR-CU07 a code is 6 digits', function (): void {
    expect(app(OtpCodes::class)->generate())->toMatch('/^\d{6}$/');
});

test('BR-CU07 the stored hash is keyed: it is neither the code nor its plain sha256', function (): void {
    $hash = app(OtpCodes::class)->hash('123456');

    expect($hash)->not->toContain('123456')
        ->and($hash)->not->toBe(hash('sha256', '123456'))
        ->and($hash)->toBe(hash_hmac('sha256', '123456', config()->string('app.key')));
});

test('a code matches its own hash only', function (): void {
    $codes = app(OtpCodes::class);
    $hash = $codes->hash('123456');

    expect($codes->matches('123456', $hash))->toBeTrue()
        ->and($codes->matches('123457', $hash))->toBeFalse();
});

test('the hash changes with the app key', function (): void {
    $before = app(OtpCodes::class)->hash('123456');
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

    expect(app(OtpCodes::class)->hash('123456'))->not->toBe($before);
});
