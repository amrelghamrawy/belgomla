<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Support\ApiException;

/**
 * D99 b: a count taken against an On Hand that has since dropped so far that its difference would take the stock
 * below zero (goods counted that were sold meanwhile). `409 count_outdated` with what the count started from, what
 * was counted and what is on hand now; the merchant counts again.
 */
final class CountOutdated extends ApiException
{
    public static function for(int $expected, int $counted, int $current): self
    {
        return new self(
            409,
            'count_outdated',
            "تغيّر الرصيد منذ بدء الجرد (كان {$expected} وأصبح {$current})، ولا يمكن تطبيق الفرق. أعد عدّ الصنف.",
            ['expected_on_hand' => $expected, 'counted' => $counted, 'on_hand' => $current],
        );
    }
}
