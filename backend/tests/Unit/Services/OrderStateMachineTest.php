<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStateMachine;

test('BR-O01 moving forward sets confirmed_at the first time the order leaves pending, even when skipping', function (OrderStatus $to): void {
    $plan = new OrderStateMachine()->plan(new Order(['status' => OrderStatus::Pending, 'confirmed_at' => null]), $to);

    expect($plan->from)->toBe(OrderStatus::Pending)
        ->and($plan->to)->toBe($to)
        ->and($plan->timestamps)->toBe(['confirmed_at'])
        ->and($plan->releasesStock)->toBeFalse()
        ->and($plan->deductsStock)->toBeFalse();
})->with(['confirmed' => [OrderStatus::Confirmed], 'preparing' => [OrderStatus::Preparing], 'out for delivery' => [OrderStatus::OutForDelivery]]);

test('BR-O01 a confirmed order keeps its confirmation time', function (): void {
    $plan = new OrderStateMachine()->plan(new Order(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()]), OrderStatus::Preparing);

    expect($plan->timestamps)->toBe([]);
});

test('BR-INV05 delivering deducts the stock and sets delivered_at', function (): void {
    $plan = new OrderStateMachine()->plan(new Order(['status' => OrderStatus::OutForDelivery, 'confirmed_at' => now()]), OrderStatus::Delivered);

    expect($plan->timestamps)->toBe(['delivered_at'])
        ->and($plan->deductsStock)->toBeTrue()
        ->and($plan->releasesStock)->toBeFalse();
});

test('BR-INV05 delivering a pending order straight away also stamps the confirmation', function (): void {
    $plan = new OrderStateMachine()->plan(new Order(['status' => OrderStatus::Pending, 'confirmed_at' => null]), OrderStatus::Delivered);

    expect($plan->timestamps)->toBe(['confirmed_at', 'delivered_at']);
});

test('BR-INV04 cancelling releases the stock and sets cancelled_at', function (OrderStatus $from): void {
    $plan = new OrderStateMachine()->plan(new Order(['status' => $from, 'confirmed_at' => null]), OrderStatus::Cancelled);

    expect($plan->timestamps)->toBe(['cancelled_at'])
        ->and($plan->releasesStock)->toBeTrue()
        ->and($plan->deductsStock)->toBeFalse();
})->with(['pending' => [OrderStatus::Pending], 'preparing' => [OrderStatus::Preparing], 'out for delivery' => [OrderStatus::OutForDelivery]]);

test('BR-O01 an invalid move is refused with 409 invalid_transition naming both statuses', function (OrderStatus $from, OrderStatus $to): void {
    expect(fn (): App\Modules\Orders\Data\TransitionPlan => new OrderStateMachine()->plan(new Order(['status' => $from]), $to))->toThrow(function (InvalidOrderTransition $e) use ($from, $to): void {
        expect($e->status)->toBe(409)
            ->and($e->errorCode)->toBe('invalid_transition')
            ->and($e->details)->toBe(['from' => $from->value, 'to' => $to->value]);
    });
})->with([
    'backwards' => [OrderStatus::Preparing, OrderStatus::Confirmed],
    'onto itself' => [OrderStatus::Confirmed, OrderStatus::Confirmed],
    'out of delivered' => [OrderStatus::Delivered, OrderStatus::Cancelled],
    'out of cancelled' => [OrderStatus::Cancelled, OrderStatus::Confirmed],
]);
