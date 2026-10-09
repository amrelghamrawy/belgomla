<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Models\TenantSetting;

beforeEach(function (): void {
    $this->customer = $this->actingAsCustomer();
    $this->sku = cartSku($this->tenant);
});

/** An active product whose SKU sells in cartons of 20, MOQ 5, base price 90, tier 10+ → 85, with 400 pieces (20 cartons) in stock. */
function cartSku(App\Modules\Tenancy\Models\Tenant $tenant, ProductStatus $status = ProductStatus::Active, int $onHand = 400): Sku
{
    return app(App\Modules\Tenancy\TenantContext::class)->runAs($tenant, function () use ($status, $onHand): Sku {
        $carton = App\Modules\Catalog\Models\Unit::query()->firstOrCreate(['name' => 'كرتونة']);
        $sku = Product::factory()
            ->state(['name' => 'الأضواء علوم', 'status' => $status])
            ->withSku(['selling_unit_id' => $carton->id, 'conversion_factor' => 20, 'moq' => 5, 'base_price' => '90.00'], onHand: $onHand)
            ->create()
            ->sku()
            ->sole();
        SkuPriceTier::factory()->create(['sku_id' => $sku->id, 'min_quantity' => 10, 'unit_price' => '85.00']);

        return $sku;
    });
}

function addToCart(int $skuId, int $quantity): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $skuId, 'quantity' => $quantity]);
}

test('BR-CU06 an empty cart has no items and a zero total', function (): void {
    $this->tenantJson('GET', '/api/store/cart')
        ->assertOk()
        ->assertExactJson(['data' => [
            'items' => [],
            'removed_items' => [],
            'items_total' => '0.00',
            'mov' => ['enabled' => false, 'amount' => null, 'satisfied' => true],
        ]]);
});

test('BR-CU06 BR-PR04 adding a sku returns the cart with the price resolved for the quantity', function (): void {
    addToCart($this->sku->id, 6)
        ->assertOk()
        ->assertExactJson(['data' => [
            'items' => [[
                'sku_id' => $this->sku->id,
                'product_name' => 'الأضواء علوم',
                'unit' => 'كرتونة',
                'quantity' => 6,
                'unit_price' => '90.00',
                'tier_min_quantity' => null,
                'line_total' => '540.00',
                'moq' => 5,
                'available' => 400,
            ]],
            'removed_items' => [],
            'items_total' => '540.00',
            'mov' => ['enabled' => false, 'amount' => null, 'satisfied' => true],
        ]]);

    $this->assertDatabaseHas('cart_items', ['sku_id' => $this->sku->id, 'quantity' => 6]);
});

test('BR-PR04 the tier price applies from its minimum quantity, the base price below it', function (int $quantity, string $unitPrice, ?int $tier, string $lineTotal): void {
    addToCart($this->sku->id, $quantity)
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_price', $unitPrice)
        ->assertJsonPath('data.items.0.tier_min_quantity', $tier)
        ->assertJsonPath('data.items.0.line_total', $lineTotal);
})->with([
    'tier min − 1' => [9, '90.00', null, '810.00'],
    'tier min' => [10, '85.00', 10, '850.00'],
]);

test('BR-CU06 adding the same sku again replaces its quantity', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    addToCart($this->sku->id, 6)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 6);

    expect($this->inTenant(fn () => CartItem::query()->count()))->toBe(1)
        ->and($this->inTenant(fn () => Cart::query()->count()))->toBe(1);
});

test('BR-MOQ01 a quantity below the moq is refused', function (): void {
    addToCart($this->sku->id, 4)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cart_line_invalid')
        ->assertJsonPath('error.items.0', ['sku_id' => $this->sku->id, 'reason' => 'below_moq', 'moq' => 5]);

    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-INV01 more than the stock is refused, with what is left in selling units', function (): void {
    addToCart($this->sku->id, 21)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cart_line_invalid')
        ->assertJsonPath('error.items.0', ['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'available' => 20]);
});

test('BR-INV01 exactly the stock is accepted', function (): void {
    addToCart($this->sku->id, 20)->assertOk();
});

test('BR-P04 D21 an inactive or coming-soon product cannot be added', function (ProductStatus $status): void {
    $sku = cartSku($this->tenant, $status);

    addToCart($sku->id, 5)
        ->assertUnprocessable()
        ->assertJsonPath('error.items.0.reason', 'product_inactive');
})->with(['inactive' => [ProductStatus::Inactive], 'coming soon' => [ProductStatus::ComingSoon]]);

test('BR-P04 a product that became inactive leaves the cart at the next load, once, with a message', function (): void {
    addToCart($this->sku->id, 5)->assertOk();
    $this->inTenant(fn () => Product::query()->update(['status' => ProductStatus::Inactive]));

    $this->tenantJson('GET', '/api/store/cart')
        ->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.removed_items', [['product_name' => 'الأضواء علوم', 'reason' => 'inactive']])
        ->assertJsonPath('data.items_total', '0.00');

    $this->tenantJson('GET', '/api/store/cart')->assertJsonPath('data.removed_items', []);
    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-MOV02 the mov check compares the items total after tier pricing', function (string $amount, bool $satisfied): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => $amount]);

    addToCart($this->sku->id, 6)
        ->assertOk()
        ->assertJsonPath('data.mov', ['enabled' => true, 'amount' => $amount, 'satisfied' => $satisfied]);
})->with([
    'total below the mov' => ['540.01', false],
    'total exactly the mov' => ['540.00', true],
    'total above the mov' => ['539.99', true],
]);

test('BR-CU06 changing a quantity re-checks it and re-prices the cart', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    $this->tenantJson('PATCH', "/api/store/cart/items/{$this->sku->id}", ['quantity' => 4])
        ->assertUnprocessable()
        ->assertJsonPath('error.items.0.reason', 'below_moq');

    $this->tenantJson('PATCH', "/api/store/cart/items/{$this->sku->id}", ['quantity' => 6])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 6)
        ->assertJsonPath('data.items_total', '540.00');
});

test('changing or removing a sku that is not in the cart is 404', function (string $method): void {
    $this->tenantJson($method, "/api/store/cart/items/{$this->sku->id}", ['quantity' => 5])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
})->with(['PATCH', 'DELETE']);

test('BR-CU06 removing a sku returns the cart without it', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    $this->tenantJson('DELETE', "/api/store/cart/items/{$this->sku->id}")
        ->assertOk()
        ->assertJsonPath('data.items', []);
});

test('BR-CU06 clearing the cart empties it', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    $this->tenantJson('DELETE', '/api/store/cart')->assertNoContent();

    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-CU06 each customer sees only their own cart', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    $this->actingAsCustomer();

    $this->tenantJson('GET', '/api/store/cart')->assertJsonPath('data.items', []);
});

test('BR-T04 a sku id of another tenant is 404 and nothing is added', function (): void {
    $foreign = cartSku($this->otherTenant());

    addToCart($foreign->id, 5)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $foreign->id, 'on_hand' => 400]);
});

test('add-to-cart validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/store/cart/items', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing sku' => [['quantity' => 5], 'sku_id'],
    'sku not an integer' => [['sku_id' => 'abc', 'quantity' => 5], 'sku_id'],
    'missing quantity' => [['sku_id' => 1], 'quantity'],
    'BR-U05 fractional quantity' => [['sku_id' => 1, 'quantity' => 2.5], 'quantity'],
    'zero quantity' => [['sku_id' => 1, 'quantity' => 0], 'quantity'],
    'quantity too large' => [['sku_id' => 1, 'quantity' => 100_001], 'quantity'],
]);

test('update quantity validation', function (): void {
    $this->tenantJson('PATCH', "/api/store/cart/items/{$this->sku->id}", ['quantity' => 0])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['quantity']]]);
});

test('BR-CU05 a customer blocked mid-session loses the cart', function (CustomerStatus $status): void {
    $this->customer->forceFill(['status' => $status])->save();

    $this->tenantJson('GET', '/api/store/cart')
        ->assertForbidden()
        ->assertJsonPath('error.code', $status->value);
    addToCart($this->sku->id, 5)->assertForbidden();
})->with(['blocked' => [CustomerStatus::Blocked], 'pending approval' => [CustomerStatus::PendingApproval]]);
