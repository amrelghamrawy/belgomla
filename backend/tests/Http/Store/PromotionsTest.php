<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Models\SkuPriceTier;
use Carbon\CarbonImmutable;

/*
| BR-PR15 (V2 Task 12, D90) on the storefront: the product list, the product page, the price list (page and file), the
| «عروض» filter and the cart show a live promotion's price and badge, to guests too. The book: cover 125.00, base 100.00,
| 10+ → 90.00, 100+ → 80.00, in «عرض الأضواء» (15% → 85.00) until 2026-11-30 22:00 UTC; another book is not on offer.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));
    [$this->product, $this->plain] = $this->inTenant(function (): array {
        $product = Product::factory()->withSku(['code' => 'BK-1', 'cover_price' => '125', 'base_price' => '100', 'moq' => 1], onHand: 1000)->create(['name' => 'الأضواء علوم']);
        SkuPriceTier::factory()->create(['sku_id' => $product->skus()->sole()->id, 'min_quantity' => 10, 'unit_price' => '90.00']);
        SkuPriceTier::factory()->create(['sku_id' => $product->skus()->sole()->id, 'min_quantity' => 100, 'unit_price' => '80.00']);

        return [$product->refresh(), Product::factory()->withSku(['code' => 'BK-2', 'base_price' => '40'], onHand: 1000)->create(['name' => 'المعلم عربي', 'created_at' => now()->subDay()])];
    });
    $this->sku = $this->inTenant(fn (): Sku => $this->product->skus()->sole());
    $this->promotion = $this->inTenant(fn (): Promotion => Promotion::factory()->covering($this->sku)->create([
        'name' => 'عرض الأضواء', 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-30 22:00:00',
    ]));
    $this->badge = ['id' => $this->promotion->id, 'name' => 'عرض الأضواء', 'discount_percent' => '15.00', 'ends_at' => '2026-11-30T22:00:00+00:00'];
});

test('BR-PR15 a guest sees the promotion price, the tiers below it and the offer badge on the product list', function (): void {
    $response = $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.sku.base_price', '85.00')
        ->assertJsonPath('data.0.sku.discount_percent', '32.00')
        ->assertJsonPath('data.0.sku.tiers', [['min_quantity' => 100, 'unit_price' => '80.00']])
        ->assertJsonPath('data.0.sku.promotion', $this->badge)
        ->assertJsonMissingPath('data.0.sku.price_source')
        ->assertJsonMissingPath('data.1.sku.promotion');

    expectDocumentedShape($response->json('data.0.sku'), 'StoreSku');
});

test('BR-PR15 the product page shows the offer', function (): void {
    $this->tenantJson('GET', "/api/store/products/{$this->product->slug}")
        ->assertOk()
        ->assertJsonPath('data.sku.base_price', '85.00')
        ->assertJsonPath('data.sku.promotion.name', 'عرض الأضواء');
});

test('BR-PR15 «عروض»: on_offer lists only the products on a live promotion', function (): void {
    $this->tenantJson('GET', '/api/store/products?on_offer=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->product->id);
    $this->tenantJson('GET', '/api/store/products?on_offer=0')->assertOk()->assertJsonCount(2, 'data');
    $this->tenantJson('GET', '/api/store/products?on_offer=maybe')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['on_offer']]]);
});

test('D90 c the offer ends on time, without waiting for the tick', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 22:00:00'));

    $this->tenantJson('GET', '/api/store/products')
        ->assertJsonPath('data.0.sku.base_price', '100.00')
        ->assertJsonMissingPath('data.0.sku.promotion');
    $this->tenantJson('GET', '/api/store/products?on_offer=1')->assertJsonCount(0, 'data');
});

test('D90 c a draft or a promotion that has not started shows nothing', function (array $attributes): void {
    $this->inTenant(fn () => $this->promotion->update($attributes));

    $this->tenantJson('GET', '/api/store/products')
        ->assertJsonPath('data.0.sku.base_price', '100.00')
        ->assertJsonMissingPath('data.0.sku.promotion');
})->with([
    'draft' => [['status' => 'draft']],
    'starts tomorrow' => [['starts_at' => '2026-11-04 00:00:00']],
]);

test('BR-PR15 the price list and its file show the promotion price', function (): void {
    $response = $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonPath('data.0.base_price', '85.00')
        ->assertJsonPath('data.0.promotion', $this->badge);
    $file = $this->tenantJson('GET', '/api/store/price-list/export')->assertOk();

    expectDocumentedShape($response->json('data.0'), 'PriceListRow');
    expect(spreadsheetRows(downloadedFile($file))[2][6])->toBe('85');
});

test('D1 prices hidden from guests hide the offer too', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonMissingPath('data.0.sku.base_price')
        ->assertJsonMissingPath('data.0.sku.promotion');
    $this->tenantJson('GET', '/api/store/price-list')->assertOk()->assertJsonMissingPath('data.0.promotion');
});

test('D90 a a negotiated price is never undercut by a promotion', function (): void {
    $customer = $this->makeCustomer();
    $this->inTenant(fn () => CustomerSkuPrice::factory()->create(['customer_id' => $customer->id, 'sku_id' => $this->sku->id, 'unit_price' => '95.00']));

    $this->actingAs($customer, 'customer')->tenantJson('GET', '/api/store/products')
        ->assertJsonPath('data.0.sku.base_price', '95.00')
        ->assertJsonPath('data.0.sku.price_source', 'customer')
        ->assertJsonMissingPath('data.0.sku.promotion');
});

test('D90 a a group price lower than the promotion wins', function (): void {
    $group = $this->inTenant(fn () => CustomerGroup::factory()->create(['discount_percent' => '20.00']));

    $this->actingAs($this->makeCustomer(['customer_group_id' => $group->id]), 'customer')->tenantJson('GET', '/api/store/products')
        ->assertJsonPath('data.0.sku.base_price', '80.00')
        ->assertJsonPath('data.0.sku.price_source', 'group')
        ->assertJsonMissingPath('data.0.sku.promotion');
});

test('BR-PR15 the cart prices a line at the promotion and names it, a tier below it wins', function (int $quantity, string $unitPrice, string $source, bool $badge): void {
    $response = $this->actingAs($this->makeCustomer(), 'customer')
        ->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->sku->id, 'quantity' => $quantity])
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_price', $unitPrice)
        ->assertJsonPath('data.items.0.price_source', $source);

    expect($response->json('data.items.0.promotion'))->toBe($badge ? $this->badge : null);
})->with([
    'promotion below base and the first tier' => [10, '85.00', 'promotion', true],
    'tier below the promotion' => [100, '80.00', 'tier', false],
]);

test('BR-PR06 BR-PR07 checkout snapshots the promotion price; after the end it charges the normal price', function (): void {
    $customer = $this->makeCustomer();
    $address = $this->addressOf($customer);
    $this->actingAs($customer, 'customer')->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->sku->id, 'quantity' => 2])->assertOk();

    $this->tenantJson('POST', '/api/store/checkout', ['address_id' => $address->id])->assertCreated();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:00:00'));
    $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->sku->id, 'quantity' => 2])->assertOk();
    $this->tenantJson('POST', '/api/store/checkout', ['address_id' => $address->id])->assertCreated();

    $this->assertDatabaseHas('order_items', ['sku_id' => $this->sku->id, 'price_source' => 'promotion', 'promotion_id' => $this->promotion->id, 'unit_price' => '85.00']);
    $this->assertDatabaseHas('order_items', ['sku_id' => $this->sku->id, 'price_source' => 'base', 'promotion_id' => null, 'unit_price' => '100.00']);
});
