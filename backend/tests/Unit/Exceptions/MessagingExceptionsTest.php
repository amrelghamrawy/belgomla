<?php

declare(strict_types=1);

use App\Modules\Messaging\Exceptions\InvalidPhoneNumber;
use App\Modules\Messaging\Exceptions\MessagingRateLimited;

test('BR-CU07 too many messages to a phone is 429 rate_limited with the wait', function (): void {
    $e = MessagingRateLimited::forPhone(1800);

    expect([$e->status, $e->errorCode, $e->details])->toBe([429, 'rate_limited', ['retry_after' => 1800]])
        ->and($e->getMessage())->toBe('تم إرسال رسائل كثيرة إلى هذا الرقم، حاول بعد قليل');
});

test('a number that is not an egyptian mobile is 422 invalid_phone', function (): void {
    $e = InvalidPhoneNumber::for('0223456789');

    expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'invalid_phone', ['phone' => '0223456789']])
        ->and($e->getMessage())->toBe('رقم الموبايل غير صحيح');
});
