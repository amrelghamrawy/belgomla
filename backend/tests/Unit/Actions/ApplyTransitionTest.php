<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Inventory\Exceptions\NotInTransaction;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Actions\ApplyTransition;
use App\Modules\Orders\Data\TransitionPlan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->orderableSku()->id => 5]);
    app(TenantContext::class)->set($this->tenant);
});

test('it saves the status, the planned timestamps and extra columns, and appends the history', function (): void {
    $this->freezeSecond();
    $plan = new TransitionPlan(OrderStatus::Pending, OrderStatus::Confirmed, ['confirmed_at']);

    DB::transaction(fn () => app(ApplyTransition::class)->handle($this->order, $plan, ActorType::System, null, 'آلي', ['customer_note' => 'محدث']));

    expect($this->order->refresh()->only(['status', 'customer_note']))->toBe(['status' => OrderStatus::Confirmed, 'customer_note' => 'محدث'])
        ->and($this->order->confirmed_at?->equalTo(now()))->toBeTrue()
        ->and(OrderStatusHistory::query()->latest('id')->first()?->only(['from_status', 'to_status', 'actor_type', 'actor_id', 'note']))->toBe([
            'from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Confirmed, 'actor_type' => ActorType::System, 'actor_id' => null, 'note' => 'آلي',
        ]);
});

test('BR-INV05 a sale movement by the system has no actor', function (): void {
    $plan = new TransitionPlan(OrderStatus::Pending, OrderStatus::Delivered, ['delivered_at'], deductsStock: true);

    DB::transaction(fn () => app(ApplyTransition::class)->handle($this->order, $plan, ActorType::System, 99));

    expect(InventoryMovement::query()->sole()->only(['actor_type', 'actor_id']))->toBe(['actor_type' => ActorType::System, 'actor_id' => null]);
});

test('BR-INV09 delivery freezes the average of that moment on the line and on its sale movement', function (?string $average): void {
    $line = $this->order->items()->sole();
    SkuInventory::query()->whereKey($line->sku_id)->update(['avg_cost' => $average]);
    $plan = new TransitionPlan(OrderStatus::Pending, OrderStatus::Delivered, ['delivered_at'], deductsStock: true);

    DB::transaction(fn () => app(ApplyTransition::class)->handle($this->order, $plan, ActorType::System, null));

    expect($line->refresh()->unit_cost)->toBe($average)
        ->and(InventoryMovement::query()->sole()->unit_cost)->toBe($average);
})->with(['a known cost' => ['6.2500'], 'D96 f no cost yet' => [null]]);

test('BR-INV09 a later receipt never changes the cost frozen on a delivered line', function (): void {
    $line = $this->order->items()->sole();
    SkuInventory::query()->whereKey($line->sku_id)->update(['avg_cost' => '6.2500']);
    $plan = new TransitionPlan(OrderStatus::Pending, OrderStatus::Delivered, ['delivered_at'], deductsStock: true);
    DB::transaction(fn () => app(ApplyTransition::class)->handle($this->order, $plan, ActorType::System, null));

    app(StockLedger::class)->receive($line->sku, 395, '9.0000');

    expect($line->refresh()->unit_cost)->toBe('6.2500');
});

test('BR-O13 D96 f a line delivered short freezes the cost of what went out', function (): void {
    $line = $this->order->items()->sole();
    SkuInventory::query()->whereKey($line->sku_id)->update(['avg_cost' => '3.1000']);
    $plan = new TransitionPlan(OrderStatus::Pending, OrderStatus::Delivered, ['delivered_at'], deductsStock: true);

    DB::transaction(fn () => app(ApplyTransition::class)->handle($this->order, $plan, ActorType::System, null, deliveredQuantities: [$line->id => 2]));

    expect($line->refresh()->only(['delivered_quantity', 'unit_cost']))->toBe(['delivered_quantity' => 2, 'unit_cost' => '3.1000']);
});

test('Tech §11 moving stock outside a transaction is refused', function (): void {
    $plan = new TransitionPlan(OrderStatus::Pending, OrderStatus::Cancelled, ['cancelled_at'], releasesStock: true);

    // RefreshDatabase wraps the default connection in a transaction, so use a fresh session without one.
    onSession('pgsql_session_a', fn () => app(ApplyTransition::class)->handle($this->order, $plan, ActorType::System, null));
})->throws(NotInTransaction::class);
