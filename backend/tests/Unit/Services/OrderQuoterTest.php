<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderQuoter;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Enums\PriceSource;
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

test('BR-O12 quoting an edit counts what the order holds as available', function (): void {
    $tight = $this->orderableSku(['code' => 'T-1'], onHand: 240);
    $order = $this->placeOrder($this->customer, [$tight->id => 10]);
    app(TenantContext::class)->set($this->tenant);

    $edit = $this->quoter->quote($this->customer->id, [['sku_id' => $tight->id, 'quantity' => 12]], $order->number)->report;
    $fresh = $this->quoter->quote($this->customer->id, [['sku_id' => $tight->id, 'quantity' => 12]])->report;

    expect($edit->problems)->toBe([])
        ->and($edit->lines[0]->sku->inventory?->available)->toBe(240)
        ->and($fresh->problemFor($tight->id)?->toArray())->toBe(['sku_id' => $tight->id, 'reason' => 'insufficient_stock', 'requested' => 12, 'available' => 2]);
});

test('BR-C05 BR-O12 quoting an edit does not count the order twice against the credit limit', function (): void {
    $order = $this->placeOrder($this->customer, [$this->sku->id => 6]);
    app(TenantContext::class)->set($this->tenant);
    $this->customer->forceFill(['credit_limit' => '720.00'])->save();

    expect($this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 8]], $order->number)->report->credit)->toBeNull()
        ->and($this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 8]])->report->credit)->toBe(['limit' => '720.00', 'exposure' => '540.00', 'order_total' => '720.00']);
});

test('BR-O12 an order past editing holds nothing for the quote', function (): void {
    $tight = $this->orderableSku(['code' => 'T-1'], onHand: 240);
    $order = $this->placeOrder($this->customer, [$tight->id => 10]);
    app(TenantContext::class)->set($this->tenant);
    $order->forceFill(['status' => App\Modules\Orders\Enums\OrderStatus::Preparing])->save();

    expect($this->quoter->quote($this->customer->id, [['sku_id' => $tight->id, 'quantity' => 12]], $order->number)->report->problemFor($tight->id)?->reason)->toBe('insufficient_stock');
});

test('BR-O12 an order of another customer is a validation error on order', function (): void {
    $order = $this->placeOrder($this->makeCustomer(['phone' => '+201099999999']), [$this->sku->id => 6]);
    app(TenantContext::class)->set($this->tenant);

    expect(fn () => $this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 6]], $order->number))->toThrow(function (CheckoutFailed $e): void {
        expect([$e->status, $e->details])->toBe([422, ['order' => ['الطلب غير موجود']]]);
    });
});

test('D88 a pre-order cannot be quoted as an edit: it is not edited', function (): void {
    $preorder = $this->placePreorder($this->customer, [$this->preorderableSku()->id => 5]);
    app(TenantContext::class)->set($this->tenant);

    expect(fn () => $this->quoter->quote($this->customer->id, [['sku_id' => $this->sku->id, 'quantity' => 6]], $preorder->number))->toThrow(function (CheckoutFailed $e): void {
        expect([$e->status, $e->details])->toBe([422, ['order' => ['الطلب غير موجود']]]);
    });
});

test('BR-O11 BR-PR14 the quote prices at the customer\'s group and lists only the tiers below it', function (): void {
    $group = CustomerGroup::factory()->create(['discount_percent' => '10.00']);
    Customer::query()->whereKey($this->customer->id)->update(['customer_group_id' => $group->id]);
    $sku = $this->orderableSku(['code' => 'G-1'], name: 'مجموعة');
    app(TenantContext::class)->set($this->tenant);

    $quote = app(OrderQuoter::class)->quote($this->customer->id, [['sku_id' => $sku->id, 'quantity' => 5]]);

    expect($quote->report->lines[0]->price->unitPrice)->toBe('81.00')
        ->and($quote->report->lines[0]->price->source)->toBe(PriceSource::Group)
        ->and($quote->tiers[$sku->id])->toBe([]);
});

test('BR-O11 BR-PR15 the merchant\'s quote prices at a live promotion and lists only the tiers below it', function (): void {
    $sku = $this->orderableSku(['code' => 'P-1'], name: 'عرض');
    $this->inTenant(fn () => App\Modules\Pricing\Models\Promotion::factory()->covering($sku)->percent('20.00')->create());
    app(TenantContext::class)->set($this->tenant);

    $quote = app(OrderQuoter::class)->quote($this->customer->id, [['sku_id' => $sku->id, 'quantity' => 5]]);

    expect($quote->report->lines[0]->price->unitPrice)->toBe('72.00')
        ->and($quote->report->lines[0]->price->source)->toBe(PriceSource::Promotion)
        ->and($quote->tiers[$sku->id])->toBe([]);
});
