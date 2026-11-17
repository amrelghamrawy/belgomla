<?php

declare(strict_types=1);

use App\Modules\Inventory\Data\BulkStockAdjustmentData;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Modules\Inventory\Data\StockReceiptData;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Http\Requests\AdjustStockRequest;
use App\Modules\Inventory\Http\Requests\BulkAdjustStockRequest;
use App\Modules\Inventory\Http\Requests\ReceiveStockRequest;
use App\Modules\Inventory\Http\Requests\SetStockCostRequest;

test('D97 an adjustment reads an integer on hand, the reason code and a trimmed note', function (): void {
    $request = AdjustStockRequest::create('/', 'POST', ['new_on_hand' => '50', 'reason' => 'damaged', 'note' => '  وقع الكرتون  ']);

    expect(StockAdjustmentData::fromRequest($request))->toEqual(new StockAdjustmentData(50, AdjustmentReason::Damaged, 'وقع الكرتون'));
});

test('D97 the movement text is the reason, then the note when there is one', function (?string $note, string $text): void {
    expect((new StockAdjustmentData(1, AdjustmentReason::Expired, $note))->text())->toBe($text);
})->with([
    'no note' => [null, 'انتهاء صلاحية'],
    'a note' => ['دفعة أغسطس', 'انتهاء صلاحية: دفعة أغسطس'],
]);

test('a bulk adjustment is keyed by sku id in the order sent', function (): void {
    $request = BulkAdjustStockRequest::create('/', 'POST', ['items' => [
        ['sku_id' => '9', 'new_on_hand' => 5, 'reason' => 'stocktake'],
        ['sku_id' => 4, 'new_on_hand' => '0', 'reason' => 'other', 'note' => ' هدية لعميل '],
    ]]);

    expect(BulkStockAdjustmentData::fromRequest($request)->items)->toEqual([
        9 => new StockAdjustmentData(5, AdjustmentReason::Stocktake),
        4 => new StockAdjustmentData(0, AdjustmentReason::Other, 'هدية لعميل'),
    ]);
});

test('BR-INV09 a receipt reads the quantity, the cost as money, the unit and a trimmed note', function (): void {
    $request = ReceiveStockRequest::create('/', 'POST', ['quantity' => '2', 'unit_cost' => '300', 'sku_unit_id' => '7', 'note' => '  مكتبة النور  ']);

    expect(StockReceiptData::fromRequest($request))->toEqual(new StockReceiptData(2, '300.00', 7, 'مكتبة النور'));
});

test('D96 a a receipt without a unit or a note is in base units with no note', function (): void {
    $request = ReceiveStockRequest::create('/', 'POST', ['quantity' => 12, 'unit_cost' => '7.25', 'sku_unit_id' => null, 'note' => '   ']);

    expect(StockReceiptData::fromRequest($request))->toEqual(new StockReceiptData(12, '7.25'));
});

test('D96 d the cost request reads the cost as money and the unit when sent', function (array $body, ?int $unit): void {
    $request = SetStockCostRequest::create('/', 'PUT', $body);

    expect([$request->unitCost(), $request->skuUnitId()])->toBe(['57.00', $unit]);
})->with([
    'with a unit' => [['unit_cost' => '57', 'sku_unit_id' => '3'], 3],
    'without one' => [['unit_cost' => '57.0'], null],
]);
