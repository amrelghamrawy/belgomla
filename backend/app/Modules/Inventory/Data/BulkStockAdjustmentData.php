<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

use App\Modules\Inventory\Http\Requests\BulkAdjustStockRequest;

/** Several counted SKUs at once (BR-INV08 bulk), keyed by SKU id in the order sent; ids are distinct. */
final readonly class BulkStockAdjustmentData
{
    /**
     * @param  array<int, StockAdjustmentData>  $items  sku id => adjustment
     */
    public function __construct(public array $items) {}

    public static function fromRequest(BulkAdjustStockRequest $request): self
    {
        $items = [];

        foreach ($request->items() as $item) {
            $items[$item['sku_id']] = new StockAdjustmentData($item['new_on_hand'], $item['reason']);
        }

        return new self($items);
    }
}
