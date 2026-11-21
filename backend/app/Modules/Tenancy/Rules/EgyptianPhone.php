<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * BR-CU04 / BR-CU07: an Egyptian mobile number in any spelling PhoneNumber accepts (normalised by the request). In
 * Tenancy so the dashboard users' phone (D112) is checked the same way as a customer's.
 */
final readonly class EgyptianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isEgyptianMobile($value)) {
            $fail('رقم الهاتف يجب أن يكون رقم موبايل مصري صحيح.');
        }
    }
}
