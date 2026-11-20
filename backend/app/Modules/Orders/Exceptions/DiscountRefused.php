<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Support\ApiException;

/** BR-PR08, D85: why a manual discount cannot be given on this order, or by this user. */
final class DiscountRefused extends ApiException
{
    /** Only a pending or confirmed order takes a discount: `409 order_locked`. */
    public static function locked(OrderStatus $status): self
    {
        return new self(409, 'order_locked', "لا يمكن تعديل أسعار طلب حالته «{$status->label()}»", ['status' => $status->value]);
    }

    /** D88: a pre-order's price is indicative and set again on conversion, so it takes no discount: `409 order_locked`. */
    public static function preorder(): self
    {
        return new self(409, 'order_locked', 'سعر الحجز المسبق مبدئي ويُحسب عند تحويله إلى طلب، فلا يقبل خصمًا', ['status' => OrderStatus::Pending->value, 'kind' => 'preorder']);
    }

    /** The new total would be less than what was already collected: `409 below_collected`. */
    public static function belowCollected(string $collected, string $total): self
    {
        return new self(409, 'below_collected', "تم تحصيل {$collected} من هذا الطلب، ولا يمكن أن يقل إجماليه عن ذلك", ['collected_amount' => $collected, 'order_total' => $total]);
    }

    /** A line id that is not a line of this order: `422 validation_failed` on that field. */
    public static function unknownLine(int $index): self
    {
        $message = 'البند غير موجود في هذا الطلب';

        return new self(422, 'validation_failed', $message, ["items.{$index}.order_item_id" => [$message]]);
    }

    /** A sales user above the store's cap, or with no cap set, or discounting the whole order: `403 discount_over_limit`. */
    public static function overLimit(?string $cap): self
    {
        return new self(403, 'discount_over_limit', $cap === null
            ? 'غير مسموح لك بإعطاء خصم'
            : "أقصى خصم مسموح لك هو {$cap}% على البند، ولا يمكنك إعطاء خصم على إجمالي الطلب", ['max_percent' => $cap]);
    }
}
