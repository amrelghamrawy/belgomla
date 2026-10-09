<?php

declare(strict_types=1);

use App\Modules\Customers\Rules\EgyptianPhone;

function phoneFailures(mixed $value): array
{
    $failures = [];

    new EgyptianPhone()->validate('phone', $value, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    return $failures;
}

test('BR-CU04 egyptian mobiles in any spelling pass', function (string $phone): void {
    expect(phoneFailures($phone))->toBe([]);
})->with(['local' => ['01012345678'], 'international' => ['+201112345678'], 'spaced' => ['015 1234 5678'], 'arabic digits' => ['٠١٢١٢٣٤٥٦٧٨']]);

test('anything else fails with an arabic message', function (mixed $value): void {
    expect(phoneFailures($value))->toBe(['رقم الهاتف يجب أن يكون رقم موبايل مصري صحيح.']);
})->with(['landline' => ['0223456789'], 'unknown prefix' => ['01312345678'], 'too short' => ['0101234'], 'not a string' => [1012345678], 'null' => [null]]);
