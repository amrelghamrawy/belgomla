<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Orders\Data\CheckoutProblem;
use App\Support\ApiException;

/**
 * BR-PR06: checkout refused as a whole, `422 checkout_failed` with every offending SKU in `items` and, when the
 * minimum order value is not reached, `details.mov` (08-api-contract.md §3). Nothing was written.
 */
final class CheckoutFailed extends ApiException
{
    /**
     * @param  list<CheckoutProblem>  $problems
     * @param  array{required: string, current: string}|null  $mov  BR-MOV02
     */
    public static function with(array $problems, ?array $mov = null): self
    {
        return new self(
            422,
            'checkout_failed',
            'تعذر إتمام الطلب، راجع العناصر التالية',
            $mov === null ? null : ['mov' => $mov],
            array_map(fn (CheckoutProblem $problem): array => $problem->toArray(), $problems),
        );
    }

    /** BR-PR06 step 1, BR-T07: the tenant was suspended; same answer as the storefront middleware. */
    public static function storeUnavailable(): self
    {
        return new self(503, 'store_unavailable', 'المتجر غير متاح حاليًا');
    }

    /** BR-CU05: only active customers order; the code is the status, as for the cart. */
    public static function customerNotActive(CustomerStatus $status): self
    {
        return new self(403, $status->value, "لا يمكن إتمام الطلب، حالة حسابك «{$status->label()}»");
    }

    /** D57: nothing to order. */
    public static function emptyCart(): self
    {
        return new self(422, 'cart_empty', 'السلة فارغة');
    }

    /** D57: the address is not one of the customer's active addresses; reported like a field error. */
    public static function addressUnavailable(): self
    {
        $message = 'عنوان التوصيل غير موجود';

        return new self(422, 'validation_failed', $message, ['address_id' => [$message]]);
    }
}
