<?php

declare(strict_types=1);

use App\Support\PhoneNumber;

test('egyptian mobile numbers are normalised to E.164', function (string $input, string $expected): void {
    expect(PhoneNumber::toE164($input))->toBe($expected)
        ->and(PhoneNumber::isEgyptianMobile($input))->toBeTrue();
})->with([
    'local vodafone' => ['01012345678', '+201012345678'],
    'local etisalat' => ['01112345678', '+201112345678'],
    'local orange' => ['01212345678', '+201212345678'],
    'local we' => ['01512345678', '+201512345678'],
    'already E.164' => ['+201012345678', '+201012345678'],
    'country code without plus' => ['201012345678', '+201012345678'],
    'international 00 prefix' => ['00201012345678', '+201012345678'],
    'without the leading zero' => ['1012345678', '+201012345678'],
    'spaces and dashes' => [' +20 101-234-5678 ', '+201012345678'],
    'arabic-indic digits' => ['٠١٠١٢٣٤٥٦٧٨', '+201012345678'],
    'eastern arabic-indic digits' => ['۰۱۰۱۲۳۴۵۶۷۸', '+201012345678'],
]);

test('anything that is not an egyptian mobile number is refused', function (string $input): void {
    expect(PhoneNumber::toE164($input))->toBeNull()
        ->and(PhoneNumber::isEgyptianMobile($input))->toBeFalse();
})->with([
    'empty' => [''],
    'unknown prefix 013' => ['01312345678'],
    'cairo landline' => ['0223456789'],
    'too short' => ['0101234567'],
    'too long' => ['010123456789'],
    'another country' => ['+966501234567'],
    'letters' => ['010abc45678'],
]);

test('the whatsapp chat id is the number without plus, suffixed @c.us', function (): void {
    expect(PhoneNumber::whatsAppChatId('+201012345678'))->toBe('201012345678@c.us');
});
