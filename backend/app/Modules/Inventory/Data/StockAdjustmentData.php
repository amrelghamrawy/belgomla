<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

use App\Modules\Inventory\Http\Requests\AdjustStockRequest;

/** A counted On Hand for one SKU, in base units, with the merchant's reason (BR-INV06 `adjustment`). */
final readonly class StockAdjustmentData
{
    public function __construct(
        public int $newOnHand,
        public string $reason,
    ) {}

    public static function fromRequest(AdjustStockRequest $request): self
    {
        return new self($request->newOnHand(), $request->reason());
    }
}
