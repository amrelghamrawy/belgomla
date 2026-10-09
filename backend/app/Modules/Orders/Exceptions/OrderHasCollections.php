<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Support\ApiException;

/** BR-O03, D7: an order with collected money is cancelled only after its collections are voided. */
final class OrderHasCollections extends ApiException
{
    public static function amount(string $collected): self
    {
        return new self(
            409,
            'has_collections',
            "تم تحصيل {$collected} من هذا الطلب، ألغِ التحصيلات أولًا",
            ['collected_amount' => $collected],
        );
    }
}
