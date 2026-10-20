<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Support\ApiException;

/** BR-O12, BR-O13: why the lines of an order cannot be changed, or delivered as asked. */
final class OrderEditRefused extends ApiException
{
    /** D88: a pre-order's lines are not edited; the customer cancels it and pre-orders again: `409 order_locked`. */
    public static function preorder(): self
    {
        return new self(409, 'order_locked', 'لا يمكن تعديل أصناف حجز مسبق، يلغيه العميل ويحجز من جديد', ['status' => OrderStatus::Pending->value, 'kind' => 'preorder']);
    }

    /** Only a pending or confirmed order has its lines edited: `409 order_locked`. */
    public static function locked(OrderStatus $status): self
    {
        return new self(409, 'order_locked', "لا يمكن تعديل أصناف طلب حالته «{$status->label()}»", ['status' => $status->value]);
    }

    /** The new total would be less than what was already collected: `409 below_collected` (BR-C03). */
    public static function belowCollected(string $collected, string $total): self
    {
        return new self(409, 'below_collected', "تم تحصيل {$collected} من هذا الطلب، ولا يمكن أن يقل إجماليه عن ذلك", ['collected_amount' => $collected, 'order_total' => $total]);
    }

    /** A line id that is not a live line of this order: `422 validation_failed` on that field (`{$field}.{$index}.order_item_id`). */
    public static function unknownLine(string $field, int $index): self
    {
        $message = 'البند غير موجود في هذا الطلب';

        return new self(422, 'validation_failed', $message, ["{$field}.{$index}.order_item_id" => [$message]]);
    }

    /** An existing line sent with another SKU: a line's SKU never changes, remove it and add the other one. */
    public static function skuChanged(int $index): self
    {
        $message = 'لا يمكن تغيير صنف بند موجود، احذفه وأضف الصنف الآخر';

        return new self(422, 'validation_failed', $message, ["lines.{$index}.sku_id" => [$message]]);
    }

    /** BR-O13: a line delivered above what was ordered: `422 validation_failed` on its quantity. */
    public static function deliveredAboveOrdered(int $index, int $ordered): self
    {
        $message = "الكمية المسلّمة أكبر من المطلوبة ({$ordered})";

        return new self(422, 'validation_failed', $message, ["delivered_quantities.{$index}.quantity" => [$message]]);
    }

    /** D86: nothing at all was delivered; that is a cancellation, not a delivery. */
    public static function nothingDelivered(): self
    {
        $message = 'لم يتم تسليم أي صنف، ألغِ الطلب بدلًا من ذلك';

        return new self(422, 'validation_failed', $message, ['delivered_quantities' => [$message]]);
    }
}
