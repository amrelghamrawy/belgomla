<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Rules;

use App\Support\OrderStatusMessage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * BR-N03: a store's order status text may use only the placeholders its status can fill (`order_status_messages.<status>`),
 * so none reaches a customer unfilled.
 */
final readonly class OrderStatusTemplate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $status = mb_substr($attribute, (int) mb_strrpos($attribute, '.') + 1);
        $unknown = OrderStatusMessage::unknownPlaceholders($value, $status);

        if ($unknown !== []) {
            $allowed = $status === 'cancelled' ? OrderStatusMessage::PLACEHOLDERS : array_diff(OrderStatusMessage::PLACEHOLDERS, [OrderStatusMessage::REASON]);
            $fail('متغير غير معروف: '.implode('، ', $unknown).'. المتغيرات المتاحة: '.implode('، ', $allowed).'.');
        }
    }
}
