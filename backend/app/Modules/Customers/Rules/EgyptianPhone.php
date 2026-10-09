<?php

declare(strict_types=1);

namespace App\Modules\Customers\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** BR-CU04 / BR-CU07: an Egyptian mobile number in any spelling PhoneNumber accepts (normalised by the request). */
final readonly class EgyptianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isEgyptianMobile($value)) {
            $fail('رقم الهاتف يجب أن يكون رقم موبايل مصري صحيح.');
        }
    }
}
