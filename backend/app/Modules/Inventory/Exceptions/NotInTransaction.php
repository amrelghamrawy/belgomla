<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/**
 * A programming error, not an API failure (rules/02 §7): stock rows were locked or changed outside the
 * caller's transaction, so the lock would be released before the change it protects. Renders as 500.
 */
final class NotInTransaction extends RuntimeException
{
    public static function for(string $operation): self
    {
        return new self("StockLedger::{$operation}() must run inside a database transaction that locked the stock row.");
    }
}
