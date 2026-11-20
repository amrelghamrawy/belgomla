<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\ApplyVerticalTemplate;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\TenantUser;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\StorefrontDemoSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 13:00:00', 'Africa/Cairo'));
    $this->inTenant(fn (): array => app(ApplyVerticalTemplate::class)->handle(Vertical::SchoolBooks));
    $this->seed(StorefrontDemoSeeder::class);
});

test('the demo activity seeds fourteen bookshops, twelve of them ordering', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $statuses = $this->inTenant(fn (): array => Customer::query()->pluck('status')->countBy(fn (CustomerStatus $status): string => $status->value)->all());

    expect($statuses)->toEqual(['active' => 12, 'invited' => 1, 'pending_approval' => 1]);
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
        ->where('kind', OrderKind::Order)
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

test('the demo store has one user per staff role, signing in with the given password', function (): void {
    app(DemoActivitySeeder::class)->setContainer(app())->__invoke(['staffPassword' => 'password']);

    $users = $this->inTenant(fn () => TenantUser::query()->orderBy('id')->get());

    expect($users->map(fn (TenantUser $user): array => [$user->email, $user->role->value])->all())->toBe([
        ['manager@alnoor.test', 'manager'],
        ['sales@alnoor.test', 'sales'],
        ['warehouse@alnoor.test', 'warehouse'],
        ['accountant@alnoor.test', 'accountant'],
    ])->and($users->every(fn (TenantUser $user): bool => Hash::check('password', $user->password)))->toBeTrue();
});

test('without a staff password the demo users get one nobody knows', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $user = $this->inTenant(fn (): TenantUser => TenantUser::query()->where('email', 'manager@alnoor.test')->sole());

    expect(Hash::check('password', $user->password))->toBeFalse();
});

test('BR-PR12 BR-PR14 two price groups hold their bookshops, a third is archived, two bookshops have their own prices', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$groups, $prices, $sources] = $this->inTenant(fn (): array => [
        CustomerGroup::query()->withCount('customers')->orderBy('sort_order')->get()
            ->map(fn (CustomerGroup $group): array => [$group->name, $group->status->value, $group->customers_count])->all(),
        CustomerSkuPrice::query()->count(),
        OrderItem::query()->pluck('price_source')->map(fn (PriceSource $source): string => $source->value)->unique()->sort()->values()->all(),
    ]);

    expect($groups)->toBe([['كبار العملاء', 'active', 3], ['مكتبات الصعيد', 'active', 3], ['عملاء موسميين', 'archived', 0]])
        ->and($prices)->toBe(5)
        ->and($sources)->toContain('group', 'customer', 'promotion');
});

test('D96 D98 every stocked SKU has a cost, so every delivered line has one', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$uncosted, $lines] = $this->inTenant(fn (): array => [
        SkuInventory::query()->where('on_hand', '>', 0)->whereNull('avg_cost')->count(),
        OrderItem::query()->where('is_bundle', false)->whereHas('order', fn ($query) => $query->where('status', OrderStatus::Delivered))->pluck('unit_cost')->all(),
    ]);

    expect($uncosted)->toBe(0)
        ->and($lines)->not->toBeEmpty()
        ->and(array_filter($lines, fn (?string $cost): bool => $cost === null))->toBe([]);
});

test('BR-PR15 the demo promotions are ended, cancelled, active, scheduled and draft', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $statuses = $this->inTenant(fn (): array => Promotion::query()->orderBy('id')->pluck('status', 'name')->map(fn (PromotionStatus $status): string => $status->value)->all());

    expect($statuses)->toBe([
        'عرض بداية الدراسة' => 'ended',
        'عرض نهاية الشهر' => 'cancelled',
        'خصم كتب الأضواء' => 'active',
        'عرض الترم الثاني' => 'scheduled',
        'تصفية ألعاب' => 'draft',
    ]);
});

test('BR-O12 BR-PR08 some confirmed demo orders were changed or discounted', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$changed, $discounted] = $this->inTenant(fn (): array => [
        OrderItemChange::query()->distinct()->count('order_id'),
        Order::query()->where('discount_amount', '>', 0)->count(),
    ]);

    expect($changed)->toBeGreaterThan(0)
        ->and($discounted)->toBeGreaterThan(0);
});

test('BR-R01 BR-C07 demo returns are refunded in cash or kept as credit, one is voided and credits pay later orders', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$methods, $voided, $applied, $open] = $this->inTenant(fn (): array => [
        OrderReturn::query()->whereNotNull('refund_method')->pluck('refund_method')->map(fn (RefundMethod $method): string => $method->value)->unique()->sort()->values()->all(),
        OrderReturn::query()->whereNotNull('voided_at')->count(),
        CashCollection::query()->where('kind', CollectionKind::Payment)->where('method', CollectionMethod::Credit)->count(),
        CustomerCredit::query()->where('remaining', '>', 0)->count(),
    ]);

    expect($methods)->toBe(['cash', 'credit'])
        ->and($voided)->toBe(1)
        ->and($applied)->toBeGreaterThan(0)
        ->and($open)->toBeGreaterThan(0);
});

test('BR-C02 a demo payment recorded wrong is voided and recorded again', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $voided = $this->inTenant(fn (): int => CashCollection::query()->whereNotNull('voided_at')->count());

    expect($voided)->toBe(1);
});

test('D100 the demo store pays rent, salaries and deliveries, one bill voided as a duplicate', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$categories, $voided] = $this->inTenant(fn (): array => [
        Expense::query()->whereNull('voided_at')->pluck('category')->map(fn (ExpenseCategory $category): string => $category->value)->unique()->sort()->values()->all(),
        Expense::query()->whereNotNull('voided_at')->pluck('void_reason')->all(),
    ]);

    expect($categories)->toContain('rent', 'salaries', 'transport', 'utilities', 'packaging', 'marketing')
        ->and($voided)->toBe(['اتسجلت مرتين']);
});

test('BR-INV stock is received at a newer cost and adjusted with a reason', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$received, $reasons] = $this->inTenant(fn (): array => [
        InventoryMovement::query()->where('type', 'receive')->count(),
        InventoryMovement::query()->where('type', 'adjustment')->pluck('reason_code')->map(fn (AdjustmentReason $reason): string => $reason->value)->unique()->sort()->values()->all(),
    ]);

    expect($received)->toBe(12)
        ->and($reasons)->toBe(['damaged', 'found', 'recording_error', 'stocktake']);
});

test('BR-O14 three bookshops pre-order the Coming Soon book and one cancels', function (): void {
    $this->seed(DemoActivitySeeder::class);

    $preorders = $this->inTenant(fn (): array => Order::query()->where('kind', OrderKind::Preorder)->orderBy('placed_at')->pluck('status')->map(fn (OrderStatus $status): string => $status->value)->all());

    expect($preorders)->toBe(['pending', 'cancelled', 'pending']);
});

test('every ordering demo bookshop has orders', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$active, $ordering] = $this->inTenant(fn (): array => [
        Customer::query()->where('status', CustomerStatus::Active)->orderBy('id')->pluck('id')->all(),
        Order::query()->where('kind', OrderKind::Order)->distinct()->orderBy('customer_id')->pluck('customer_id')->all(),
    ]);

    expect($ordering)->toBe($active);
});

test('D36 the low-stock titles run low through a pending school order, the stocktake corrects only a few copies', function (): void {
    $this->seed(DemoActivitySeeder::class);

    [$school, $stocktake] = $this->inTenant(fn (): array => [
        Order::query()->where('customer_note', 'طلبية مدرسة للترم الثاني')->sole()->status,
        InventoryMovement::query()->where('reason_code', AdjustmentReason::Stocktake)->pluck('quantity')->all(),
    ]);

    expect($school)->toBe(OrderStatus::Pending)
        ->and(array_filter($stocktake, fn (int $quantity): bool => abs($quantity) >= 20))->toBe([]);
});
