<?php

declare(strict_types=1);

use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Collections\Queries\CustomerExposureQuery;
use App\Modules\Orders\Contracts\CustomerExposure;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\NullCustomerExposure;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = $this->makeCustomer();
    $this->order = fn (string $state, string $total, string $collected = '0.00', string $returned = '0.00', ?int $customerId = null): Order => Order::factory()->{$state}()->create([
        'customer_id' => $customerId ?? $this->customer->id, 'items_total' => $total, 'order_total' => $total, 'returned_total' => $returned,
        'collected_amount' => $collected, 'collection_status' => CollectionStatus::for($collected, $total),
    ]);
});

test('D77 exposure = net owed on delivered orders + net totals of open orders − unused credit', function (): void {
    ($this->order)('delivered', '500.00', '200.00', '100.00');
    ($this->order)('delivered', '300.00', '300.00');
    ($this->order)('pending', '150.00');
    ($this->order)('outForDelivery', '250.00', '50.00');
    ($this->order)('cancelled', '999.00');
    ($this->order)('delivered', '700.00', customerId: $this->makeCustomer(['phone' => '+201000000777'])->id);
    CustomerCredit::factory()->for($this->customer)->create(['amount' => '80.00', 'remaining' => '60.00']);
    CustomerCredit::factory()->for($this->customer)->voided()->create(['amount' => '500.00']);

    expect(app(CustomerExposureQuery::class)->of($this->customer))->toBe('540.00')
        ->and(app(CustomerExposureQuery::class)->credit($this->customer))->toBe('60.00');
});

test('D77 a customer with nothing has no exposure', function (): void {
    expect(app(CustomerExposureQuery::class)->of($this->customer))->toBe('0.00');
});

test('D77 credit beyond what is owed gives a negative exposure', function (): void {
    CustomerCredit::factory()->for($this->customer)->create(['amount' => '80.00']);

    expect(app(CustomerExposureQuery::class)->of($this->customer))->toBe('-80.00');
});

test('Collections fills the exposure contract, and without it no figure is given', function (): void {
    expect(app(CustomerExposure::class))->toBeInstanceOf(CustomerExposureQuery::class)
        ->and(new NullCustomerExposure()->of($this->customer))->toBeNull();
});

test('D88 an open pre-order counts at what is left of its indicative total; a converted or cancelled one adds nothing', function (): void {
    ($this->order)('preorder', '450.00');
    ($this->order)('converted', '0.00');
    Order::factory()->preorder()->cancelled()->create(['customer_id' => $this->customer->id, 'items_total' => '900.00', 'order_total' => '900.00']);

    expect(app(CustomerExposureQuery::class)->of($this->customer))->toBe('450.00');
});
