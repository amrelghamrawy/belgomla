<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/** BR-PR12, BR-PR13 (V2 Task 11, D89): why a customer group or a customer's price was not changed. */
final class CustomerPricingRefused extends ApiException
{
    public static function duplicateGroupName(): self
    {
        return new self(409, 'duplicate_name', 'يوجد مستوى عملاء بنفس الاسم');
    }

    /** D89: customers join only an active group; restore the group first. */
    public static function groupArchived(): self
    {
        return new self(409, 'group_archived', 'هذا المستوى مؤرشف، أعد تفعيله أولًا');
    }

    public static function unknownGroup(string $field = 'customer_group_id'): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', [$field => ['المستوى غير موجود']]);
    }

    public static function unknownCustomers(): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['customer_ids' => ['بعض العملاء غير موجودين']]);
    }

    public static function noPrice(): self
    {
        return new self(404, 'not_found', 'لا يوجد سعر خاص لهذا الصنف');
    }
}
