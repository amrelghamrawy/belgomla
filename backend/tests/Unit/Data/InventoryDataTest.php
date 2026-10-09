<?php

declare(strict_types=1);

use App\Modules\Inventory\Data\BulkStockAdjustmentData;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Modules\Inventory\Http\Requests\AdjustStockRequest;
use App\Modules\Inventory\Http\Requests\BulkAdjustStockRequest;

test('an adjustment reads an integer on hand and a trimmed reason', function (): void {
    $request = AdjustStockRequest::create('/', 'POST', ['new_on_hand' => '50', 'reason' => '  جرد  ']);

    expect(StockAdjustmentData::fromRequest($request))->toEqual(new StockAdjustmentData(50, 'جرد'));
});

test('a bulk adjustment is keyed by sku id in the order sent', function (): void {
    $request = BulkAdjustStockRequest::create('/', 'POST', ['items' => [
        ['sku_id' => '9', 'new_on_hand' => 5, 'reason' => 'جرد'],
        ['sku_id' => 4, 'new_on_hand' => '0', 'reason' => ' تالف '],
    ]]);

    expect(BulkStockAdjustmentData::fromRequest($request)->items)->toEqual([
        9 => new StockAdjustmentData(5, 'جرد'),
        4 => new StockAdjustmentData(0, 'تالف'),
    ]);
});
