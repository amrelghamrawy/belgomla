<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\PreorderDemand;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Data\PreorderFiltersData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PreorderState;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Queries\PreorderDemandQuery;
use App\Modules\Orders\Queries\PreordersQuery;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->sku = $this->preorderableSku();
    $this->other = $this->preorderableSku(name: 'أطلس ٢٠٢٧');
    [$this->a, $this->b] = [$this->makeCustomer(), $this->makeCustomer()];
    app(TenantContext::class)->set($this->tenant);
    $this->product = fn (): Product => Product::query()->findOrFail($this->sku->product_id);
});

test('BR-O14 the demand is the open pre-ordered quantity, the customers and the pre-orders holding it', function (): void {
    $this->placePreorder($this->a, [$this->sku->id => 10]);
    $this->placePreorder($this->a, [$this->sku->id => 5, $this->other->id => 7]);
    $this->placePreorder($this->b, [$this->sku->id => 6]);

    expect(app(PreorderDemandQuery::class)->of(($this->product)()))->toBe(['quantity' => 21, 'customers' => 2, 'preorders' => 3]);
});

test('D88 converted units, converted and cancelled pre-orders, removed lines and normal orders are not demand', function (): void {
    $partly = $this->placePreorder($this->a, [$this->sku->id => 10]);
    OrderItem::query()->where('order_id', $partly->id)->update(['converted_quantity' => 4]);
    $this->placePreorder($this->b, [$this->sku->id => 8])->forceFill(['status' => OrderStatus::Cancelled])->save();
    $converted = $this->placePreorder($this->b, [$this->sku->id => 5]);
    OrderItem::query()->where('order_id', $converted->id)->update(['converted_quantity' => 5]);
    $converted->forceFill(['converted_at' => now()])->save();
    $removed = $this->placePreorder($this->b, [$this->sku->id => 9]);
    OrderItem::query()->where('order_id', $removed->id)->update(['removed_at' => now()]);
    Order::factory()->withItems([['sku_id' => $this->sku->id, 'quantity' => 50]])->create();

    expect(app(PreorderDemandQuery::class)->of(($this->product)()))->toBe(['quantity' => 6, 'customers' => 1, 'preorders' => 1]);
});

test('BR-O14 a product nobody pre-ordered has no demand', function (): void {
    expect(app(PreorderDemandQuery::class)->of(($this->product)()))->toBe(['quantity' => 0, 'customers' => 0, 'preorders' => 0]);
});

test('BR-O14 the open quantity per sku, for the cap check, leaves out skus nobody pre-ordered', function (): void {
    $this->placePreorder($this->a, [$this->sku->id => 10]);
    $this->placePreorder($this->b, [$this->sku->id => 5]);

    expect(app(PreorderDemandQuery::class)->openQuantities([$this->sku->id, $this->other->id]))->toBe([$this->sku->id => 15]);
});

test('BR-O14 the demand is another tenant\'s business only on its own host', function (): void {
    $theirs = $this->preorderableSku(tenant: $other = $this->otherTenant());
    $this->placePreorder($this->makeCustomer(tenant: $other), [$theirs->id => 10]);

    expect(app(PreorderDemandQuery::class)->openQuantities([$theirs->id]))->toBe([]);
});

test('BR-O14 catalog reads the demand through its contract', function (): void {
    expect(app(PreorderDemand::class))->toBeInstanceOf(PreorderDemandQuery::class);
});

test('BR-O14 the pre-order list runs oldest first and filters by product and by where each stands', function (): void {
    $first = $this->placePreorder($this->a, [$this->sku->id => 10]);
    $this->travel(1)->minutes();
    $second = $this->placePreorder($this->b, [$this->other->id => 5]);
    $this->travel(1)->minutes();
    $cancelled = $this->placePreorder($this->b, [$this->sku->id => 5]);
    $cancelled->forceFill(['status' => OrderStatus::Cancelled])->save();
    $converted = $this->placePreorder($this->a, [$this->sku->id => 5]);
    $converted->forceFill(['converted_at' => now()])->save();
    $this->placeOrder($this->a, [$this->orderableSku()->id => 5]);

    $numbers = fn (?int $productId, ?PreorderState $state): array => app(PreordersQuery::class)->paginate(new PreorderFiltersData($productId, $state), 24)->getCollection()->pluck('number')->all();

    expect($numbers(null, null))->toBe([$first->number, $second->number, $cancelled->number, $converted->number])
        ->and($numbers($this->sku->product_id, null))->toBe([$first->number, $cancelled->number, $converted->number])
        ->and($numbers(null, PreorderState::Open))->toBe([$first->number, $second->number])
        ->and($numbers(null, PreorderState::Converted))->toBe([$converted->number])
        ->and($numbers($this->sku->product_id, PreorderState::Cancelled))->toBe([$cancelled->number]);
});
