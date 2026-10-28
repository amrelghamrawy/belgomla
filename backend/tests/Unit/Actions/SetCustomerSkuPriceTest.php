<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Actions\SetCustomerSkuPrice;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = $this->makeCustomer();
    $this->sku = Product::factory()->withSku(['base_price' => '90.00'])->create()->sku()->sole();
    $this->unit = App\Modules\Catalog\Models\SkuUnit::query()->findOrFail($this->defaultUnitId($this->sku));
});

test('BR-PR13 a negotiated price is saved and audited on the customer', function (): void {
    $price = app(SetCustomerSkuPrice::class)->handle($this->customer, $this->unit, '82.50', 'اتفاق موسم 2026');

    expect($price->only(['customer_id', 'sku_id', 'sku_unit_id', 'unit_price', 'note']))->toBe(['customer_id' => $this->customer->id, 'sku_id' => $this->sku->id, 'sku_unit_id' => $this->unit->id, 'unit_price' => '82.50', 'note' => 'اتفاق موسم 2026'])
        ->and(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'old_values', 'new_values']))->toEqual([
            'action' => 'customer.price_set', 'entity_type' => 'customers', 'entity_id' => $this->customer->id,
            'old_values' => null, 'new_values' => ['sku_id' => $this->sku->id, 'sku_unit_id' => $this->unit->id, 'unit_price' => '82.50', 'note' => 'اتفاق موسم 2026'],
        ]);
});

test('D89 a negotiated price may be above the normal price', function (): void {
    expect(app(SetCustomerSkuPrice::class)->handle($this->customer, $this->unit, '120.00', null)->unit_price)->toBe('120.00');
});

test('BR-PR13 sending a price again replaces it, audited with the old one', function (): void {
    app(SetCustomerSkuPrice::class)->handle($this->customer, $this->unit, '82.50', null);

    app(SetCustomerSkuPrice::class)->handle($this->customer, $this->unit, '80.00', null);

    expect(CustomerSkuPrice::query()->sole()->unit_price)->toBe('80.00')
        ->and(AuditLog::query()->latest('id')->first()?->only(['old_values', 'new_values']))->toBe([
            'old_values' => ['unit_price' => '82.50'], 'new_values' => ['unit_price' => '80.00'],
        ]);
});

test('BR-PR13 the same price and note again writes nothing', function (): void {
    app(SetCustomerSkuPrice::class)->handle($this->customer, $this->unit, '82.50', 'x');

    app(SetCustomerSkuPrice::class)->handle($this->customer, $this->unit, '82.5', 'x');

    expect(AuditLog::query()->count())->toBe(1);
});

test('BR-PR13 D91 the database keeps one positive price per customer and sku unit', function (string $sql): void {
    CustomerSkuPrice::factory()->create(['customer_id' => $this->customer->id, 'sku_id' => $this->sku->id]);

    DB::statement($sql, [$this->tenant->id, $this->customer->id, $this->sku->id, $this->unit->id]);
})->with([
    'duplicate' => ['insert into customer_sku_prices (tenant_id, customer_id, sku_id, sku_unit_id, unit_price) values (?, ?, ?, ?, 10)'],
    'zero price' => ['update customer_sku_prices set unit_price = 0 where tenant_id = ? and customer_id = ? and sku_id = ? and sku_unit_id = ?'],
])->throws(QueryException::class);

test('D91 BR-PR13 each unit of a sku has its own negotiated price', function (): void {
    $box = $this->addUnit($this->sku, ['base_price' => '1000.00']);

    app(SetCustomerSkuPrice::class)->handle($this->customer, $this->unit, '82.50', null);
    app(SetCustomerSkuPrice::class)->handle($this->customer, $box, '950.00', null);

    expect(CustomerSkuPrice::query()->orderBy('id')->get()->map->only(['sku_id', 'sku_unit_id', 'unit_price'])->all())->toBe([
        ['sku_id' => $this->sku->id, 'sku_unit_id' => $this->unit->id, 'unit_price' => '82.50'],
        ['sku_id' => $this->sku->id, 'sku_unit_id' => $box->id, 'unit_price' => '950.00'],
    ]);
});
