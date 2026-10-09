<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Inventory\Exceptions\NotInTransaction;
use App\Modules\Inventory\Models\InventoryMovement;
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

test('Tech §11 moving stock outside a transaction is refused', function (): void {
    $plan = new TransitionPlan(OrderStatus::Pending, OrderStatus::Cancelled, ['cancelled_at'], releasesStock: true);

    // RefreshDatabase wraps the default connection in a transaction, so use a fresh session without one.
    onSession('pgsql_session_a', fn () => app(ApplyTransition::class)->handle($this->order, $plan, ActorType::System, null));
})->throws(NotInTransaction::class);
