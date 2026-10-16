<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Rules;

use App\Support\InvitationMessage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** BR-CU08: a merchant's invitation text may use only the known placeholders, so none reaches a customer unfilled. */
final readonly class InvitationTemplate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $unknown = InvitationMessage::unknownPlaceholders($value);

        if ($unknown !== []) {
            $fail('متغير غير معروف: '.implode('، ', $unknown).'. المتغيرات المتاحة: '.implode('، ', InvitationMessage::PLACEHOLDERS).'.');
        }
    }
}
