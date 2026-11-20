<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Orders\Data\CheckoutProblem;
use App\Support\ApiException;

/**
 * BR-PR06: checkout refused as a whole, `422 checkout_failed` with every offending SKU in `items`, `details.mov` when
 * the minimum order value is not reached, and `details.credit` when the order passes the credit limit (BR-C05, D78)
 * (08-api-contract.md §3). Nothing was written.
 */
final class CheckoutFailed extends ApiException
{
    /**
     * @param  list<CheckoutProblem>  $problems
     * @param  array{required: string, current: string}|null  $mov  BR-MOV02
     * @param  array{limit: string, exposure: string, order_total: string}|null  $credit  BR-C05
     */
    public static function with(array $problems, ?array $mov = null, ?array $credit = null): self
    {
        $details = array_filter(['mov' => $mov, 'credit' => $credit], fn (?array $detail): bool => $detail !== null);

        return new self(
            422,
            'checkout_failed',
            'تعذر إتمام الطلب، راجع العناصر التالية',
            $details === [] ? null : $details,
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

    /** BR-O11: the customer of a merchant's order is not one of this store's; reported like a field error. */
    public static function customerUnavailable(): self
    {
        $message = 'العميل غير موجود';

        return new self(422, 'validation_failed', $message, ['customer_id' => [$message]]);
    }

    /** BR-O11: a line of a merchant's order names a SKU unit this store does not have (`lines.{index}.sku_unit_id`, D91). */
    public static function skuUnavailable(int $index): self
    {
        $message = 'المنتج غير موجود';

        return new self(422, 'validation_failed', $message, ["lines.{$index}.sku_unit_id" => [$message]]);
    }

    /** BR-O12: the order a quote edits is not one of this customer's orders in this store (`order`). */
    public static function orderUnavailable(): self
    {
        $message = 'الطلب غير موجود';

        return new self(422, 'validation_failed', $message, ['order' => [$message]]);
    }
}
