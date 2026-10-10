<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\ApplyVerticalTemplate;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Enums\Vertical;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\StorefrontDemoSeeder;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 13:00:00', 'Africa/Cairo'));
    $this->inTenant(fn (): array => app(ApplyVerticalTemplate::class)->handle(Vertical::SchoolBooks));
    $this->seed(StorefrontDemoSeeder::class);
});

test('the demo activity seeds fourteen bookshops, twelve of them ordering', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $statuses = $this->inTenant(fn (): array => Customer::query()->pluck('status')->countBy(fn (CustomerStatus $status): string => $status->value)->all());

    expect($statuses)->toBe(['active' => 12, 'invited' => 1, 'pending_approval' => 1]);
});

test('the demo orders span two months, end now and reach every status', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$statuses, $first, $last] = $this->inTenant(fn (): array => [
        Order::query()->pluck('status')->map(fn (OrderStatus $status): string => $status->value)->unique()->sort()->values()->all(),
        Order::query()->min('placed_at'),
        Order::query()->max('placed_at'),
    ]);

    expect($statuses)->toBe(['cancelled', 'confirmed', 'delivered', 'out_for_delivery', 'pending', 'preparing'])
        ->and(CarbonImmutable::parse($first)->greaterThanOrEqualTo(now()->subDays(61)))->toBeTrue()
        ->and(CarbonImmutable::parse($last)->lessThanOrEqualTo(now()))->toBeTrue();
});

test('D10 two pending orders are older than the stale threshold', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $stale = $this->inTenant(fn (): int => Order::query()
        ->where('status', OrderStatus::Pending)
        ->where('placed_at', '<', now()->subHours(24))
        ->count());

    expect($stale)->toBe(2);
});

test('D59 delivered orders are paid in full, in part or left on account', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $collection = $this->inTenant(fn (): array => Order::query()
        ->where('status', OrderStatus::Delivered)
        ->pluck('collection_status')
        ->map(fn (CollectionStatus $status): string => $status->value)
        ->unique()->sort()->values()->all());

    expect($collection)->toBe(['collected', 'partially_collected', 'unpaid']);
});

test('D36 two carton titles are left below the low-stock threshold', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $available = $this->inTenant(fn (): array => Sku::query()
        ->whereIn('code', ['BK-008', 'BK-016'])
        ->orderBy('code')
        ->pluck('id')
        ->map(fn (int $id): int => SkuInventory::query()->where('sku_id', $id)->sole()->available)
        ->all());

    expect($available)->toBe([25, 25]);
});

test('only the last day of notifications stays unread', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $oldUnread = $this->inTenant(fn (): int => MerchantNotification::query()
        ->whereNull('read_at')
        ->where('created_at', '<', now()->subDay())
        ->count());

    expect($oldUnread)->toBe(0);
});

test('seeding the activity twice changes nothing', function (): void {
    $this->seed(DemoActivitySeeder::class);
    $orders = $this->inTenant(fn (): int => Order::query()->count());

    $this->seed(DemoActivitySeeder::class);

    expect($this->inTenant(fn (): int => Order::query()->count()))->toBe($orders)
        ->and($this->inTenant(fn (): int => Customer::query()->count()))->toBe(14);
});

test('the activity refuses to run without an orderable catalog', function (): void {
    $this->inTenant(fn (): int => Product::query()->update(['status' => ProductStatus::Inactive]));

    expect(fn () => $this->seed(DemoActivitySeeder::class))
        ->toThrow(RuntimeException::class, 'The demo activity needs the demo catalog: run StorefrontDemoSeeder first.');
});
