<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('to array', function (): void {
    $movement = $this->inTenant(fn () => InventoryMovement::factory()->create()->fresh());

    expect(array_keys($movement->toArray()))->toBe([
        'id', 'tenant_id', 'sku_id', 'type', 'quantity', 'on_hand_after', 'order_id', 'reason', 'actor_type', 'actor_id', 'created_at',
    ]);
});

test('casts: enums for type and actor, integers, an immutable created_at and no updated_at', function (): void {
    [$order, $movement] = $this->inTenant(function (): array {
        $order = Order::factory()->create();

        return [$order, InventoryMovement::factory()->sale(6, 4, orderId: $order->id)->create()->fresh()];
    });

    expect($movement->type)->toBe(MovementType::Sale)
        ->and($movement->actor_type)->toBe(ActorType::System)
        ->and($movement->quantity)->toBe(-6)
        ->and($movement->on_hand_after)->toBe(4)
        ->and($movement->order_id)->toBe($order->id)
        ->and($movement->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(InventoryMovement::UPDATED_AT)->toBeNull();
});

test('a movement belongs to its sku', function (): void {
    [$sku, $movement] = $this->inTenant(function (): array {
        $sku = Sku::factory()->create();

        return [$sku, InventoryMovement::factory()->for($sku)->create()];
    });

    expect($this->inTenant(fn () => $movement->sku->is($sku)))->toBeTrue();
});

test('BR-T03 a movement created in tenant context gets that tenant id', function (): void {
    expect($this->inTenant(fn () => InventoryMovement::factory()->create())->tenant_id)->toBe($this->tenant->id);
});

test('BR-T03 creating a movement without a tenant in context throws', function (): void {
    InventoryMovement::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 movement queries without a tenant in context return nothing', function (): void {
    $this->inTenant(fn () => InventoryMovement::factory()->create());
    app(TenantContext::class)->clear();

    expect(InventoryMovement::query()->count())->toBe(0);
});

test('BR-INV06 the database refuses an unknown movement type', function (): void {
    $movement = $this->inTenant(fn () => InventoryMovement::factory()->create());

    expect(fn () => DB::table('inventory_movements')->where('id', $movement->id)->update(['type' => 'transfer']))
        ->toThrow(QueryException::class, 'inventory_movements_type_known');
});

test('database checks guard the adjustment reason and the resulting on hand', function (array $attributes, string $constraint): void {
    expect(fn () => $this->inTenant(fn () => InventoryMovement::factory()->create($attributes)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'BR-INV06 adjustment without a reason' => [['type' => MovementType::Adjustment, 'reason' => null], 'inventory_movements_adjustment_reason'],
    'BR-INV02 negative on hand after' => [['on_hand_after' => -1], 'inventory_movements_on_hand_after_non_negative'],
]);
