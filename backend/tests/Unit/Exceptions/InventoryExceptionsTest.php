<?php

declare(strict_types=1);

use App\Modules\Inventory\Exceptions\BelowReserved;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\NotInTransaction;

test('BR-INV08 below reserved names the holding orders', function (): void {
    $e = BelowReserved::for(50, 80, [
        ['number' => 1024, 'status' => 'confirmed', 'quantity_base' => 60],
        ['number' => 1031, 'status' => 'pending', 'quantity_base' => 20],
    ]);

    expect([$e->status, $e->errorCode, $e->reserved()])->toBe([422, 'inventory_below_reserved', 80])
        ->and($e->getMessage())->toBe('لا يمكن تقليل المخزون إلى 50 لأن 80 وحدة محجوزة للطلبات: #1024، #1031. ألغِ الطلبات أو سلّمها أولًا.');
});

test('BR-INV08 below reserved without known orders still explains the reservation', function (): void {
    $e = BelowReserved::for(5, 8, []);

    expect($e->getMessage())->toBe('لا يمكن تقليل المخزون إلى 5 لأن 8 وحدة محجوزة لطلبات قائمة. ألغِ الطلبات أو سلّمها أولًا.')
        ->and($e->details)->toBe(['reserved' => 8, 'min_allowed' => 8, 'orders' => []]);
});

test('BR-INV01 insufficient stock carries the sku and what is available', function (): void {
    $e = InsufficientStock::for(12, 3);

    expect([$e->status, $e->errorCode, $e->getMessage()])->toBe([422, 'insufficient_stock', 'الكمية المطلوبة أكبر من المتاح في المخزون'])
        ->and($e->items)->toBe([['sku_id' => 12, 'reason' => 'insufficient_stock', 'available' => 3]]);
});

test('not in transaction is a plain runtime error naming the operation', function (): void {
    expect(NotInTransaction::for('reserve'))->toBeInstanceOf(RuntimeException::class)
        ->getMessage()->toBe('StockLedger::reserve() must run inside a database transaction that locked the stock row.');
});
