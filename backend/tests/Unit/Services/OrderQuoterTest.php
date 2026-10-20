<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderQuoter;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->customer = $this->makeCustomer();
    $this->sku = $this->orderableSku();
    app(TenantContext::class)->set($this->tenant);
    $this->quoter = app(OrderQuoter::class);
});

test('BR-O11 the quote prices the lines as checkout would, with each sku\'s tiers', function (): void {
    $quote = $this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 12]]);

    expect($quote->report->total)->toBe('1020.00')
        ->and($quote->report->problems)->toBe([])
        ->and($quote->report->lines[0]->price->unitPrice)->toBe('85.00')
        ->and($quote->tiers)->toEqual([$this->sku->id => [new TierData(10, '85.00')]]);
});

test('BR-O11 the quote reports problems, the mov and the credit instead of refusing', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '5000.00']);
    $this->customer->forceFill(['credit_limit' => '10.00'])->save();

    $report = $this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 3]])->report;

    expect($report->problemFor($this->sku->id)?->reason)->toBe('below_moq')
        ->and($report->mov)->toBe(['required' => '5000.00', 'current' => '270.00'])
        ->and($report->credit)->toBe(['limit' => '10.00', 'exposure' => '0.00', 'order_total' => '270.00']);
});

test('BR-INV01 the quote reads the stock as it is now, reserved units excluded', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['reserved' => 300]);

    $report = $this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 6]])->report;

    expect($report->problemFor($this->sku->id)?->toArray())->toBe(['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'requested' => 6, 'available' => 5]);
});

test('BR-O11 the quote writes nothing', function (): void {
    $this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 12]]);

    expect(Order::query()->count())->toBe(0)
        ->and(SkuInventory::query()->findOrFail($this->sku->id)->reserved)->toBe(0);
});

test('BR-O11 the customer is found whatever the status; an unknown one is a field error', function (): void {
    $this->customer->forceFill(['status' => CustomerStatus::Blocked])->save();

    expect($this->quoter->customer($this->customer->id)->id)->toBe($this->customer->id)
        ->and(fn () => $this->quoter->customer(999999))->toThrow(function (CheckoutFailed $e): void {
            expect($e->details)->toBe(['customer_id' => ['العميل غير موجود']]);
        });
});

test('BR-O11 the lines keep the order typed, and an unknown sku names its line', function (): void {
    $other = $this->orderableSku(name: 'كراسة');

    $lines = $this->quoter->lines([['sku_id' => $other->id, 'quantity' => 2], ['sku_id' => $this->sku->id, 'quantity' => 7]]);

    expect(array_map(fn (CartLineInput $line): array => [$line->sku->id, $line->quantity], $lines))->toBe([[$other->id, 2], [$this->sku->id, 7]])
        ->and(fn () => $this->quoter->lines([['sku_id' => $this->sku->id, 'quantity' => 1], ['sku_id' => 999999, 'quantity' => 1]]))->toThrow(function (CheckoutFailed $e): void {
            expect($e->details)->toBe(['lines.1.sku_id' => ['المنتج غير موجود']]);
        });
});

test('a sku without a stock row quotes as nothing available', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->delete();

    $report = $this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 5]])->report;

    expect($report->problemFor($this->sku->id)?->toArray())->toBe(['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 0]);
});
