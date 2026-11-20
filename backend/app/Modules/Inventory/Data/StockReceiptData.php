<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

use App\Modules\Inventory\Http\Requests\ReceiveStockRequest;

/**
 * Goods received (BR-INV09): `quantity` whole units of the SKU unit named (null = the base unit, factor 1) at
 * `unitCost` money per that unit, with the merchant's optional note (e.g. the supplier).
 */
final readonly class StockReceiptData
{
    public function __construct(
        public int $quantity,
        public string $unitCost,
        public ?int $skuUnitId = null,
        public ?string $note = null,
    ) {}

    public static function fromRequest(ReceiveStockRequest $request): self
    {
        return new self($request->quantity(), $request->unitCost(), $request->skuUnitId(), $request->note());
    }
}
