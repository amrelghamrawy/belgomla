<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderStatus;

test('every orders enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))
        ->and(array_filter($labels, fn (string $label): bool => $label === ''))->toBe([]);
})->with([OrderStatus::class, CollectionStatus::class, CancelledBy::class, NotificationType::class]);

test('BR-O01 the order statuses match the domain model', function (): void {
    expect(array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::cases()))
        ->toBe(['pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled']);
});

test('BR-C03 the collection statuses match the domain model', function (): void {
    expect(array_map(fn (CollectionStatus $status): string => $status->value, CollectionStatus::cases()))
        ->toBe(['unpaid', 'partially_collected', 'collected']);
});

test('BR-N02 the notification types match the domain model', function (): void {
    expect(array_map(fn (NotificationType $type): string => $type->value, NotificationType::cases()))->toBe(['new_order', 'customer_cancelled']);
});

test('BR-O01 delivered and cancelled are terminal', function (OrderStatus $status, bool $terminal): void {
    expect($status->isTerminal())->toBe($terminal);
})->with([
    'pending' => [OrderStatus::Pending, false],
    'confirmed' => [OrderStatus::Confirmed, false],
    'preparing' => [OrderStatus::Preparing, false],
    'out for delivery' => [OrderStatus::OutForDelivery, false],
    'delivered' => [OrderStatus::Delivered, true],
    'cancelled' => [OrderStatus::Cancelled, true],
]);

test('BR-O01 the full transition matrix', function (OrderStatus $from, OrderStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'pending → pending' => [OrderStatus::Pending, OrderStatus::Pending, false],
    'pending → confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed, true],
    'pending → preparing (skip)' => [OrderStatus::Pending, OrderStatus::Preparing, true],
    'pending → out for delivery (skip)' => [OrderStatus::Pending, OrderStatus::OutForDelivery, true],
    'pending → delivered (skip)' => [OrderStatus::Pending, OrderStatus::Delivered, true],
    'pending → cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled, true],
    'confirmed → pending (back)' => [OrderStatus::Confirmed, OrderStatus::Pending, false],
    'confirmed → confirmed' => [OrderStatus::Confirmed, OrderStatus::Confirmed, false],
    'confirmed → preparing' => [OrderStatus::Confirmed, OrderStatus::Preparing, true],
    'confirmed → out for delivery (skip)' => [OrderStatus::Confirmed, OrderStatus::OutForDelivery, true],
    'confirmed → delivered (skip)' => [OrderStatus::Confirmed, OrderStatus::Delivered, true],
    'confirmed → cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled, true],
    'preparing → pending (back)' => [OrderStatus::Preparing, OrderStatus::Pending, false],
    'preparing → confirmed (back)' => [OrderStatus::Preparing, OrderStatus::Confirmed, false],
    'preparing → preparing' => [OrderStatus::Preparing, OrderStatus::Preparing, false],
    'preparing → out for delivery' => [OrderStatus::Preparing, OrderStatus::OutForDelivery, true],
    'preparing → delivered (skip)' => [OrderStatus::Preparing, OrderStatus::Delivered, true],
    'preparing → cancelled' => [OrderStatus::Preparing, OrderStatus::Cancelled, true],
    'out for delivery → pending (back)' => [OrderStatus::OutForDelivery, OrderStatus::Pending, false],
    'out for delivery → confirmed (back)' => [OrderStatus::OutForDelivery, OrderStatus::Confirmed, false],
    'out for delivery → preparing (back)' => [OrderStatus::OutForDelivery, OrderStatus::Preparing, false],
    'out for delivery → out for delivery' => [OrderStatus::OutForDelivery, OrderStatus::OutForDelivery, false],
    'out for delivery → delivered' => [OrderStatus::OutForDelivery, OrderStatus::Delivered, true],
    'out for delivery → cancelled' => [OrderStatus::OutForDelivery, OrderStatus::Cancelled, true],
    'delivered → pending' => [OrderStatus::Delivered, OrderStatus::Pending, false],
    'delivered → confirmed' => [OrderStatus::Delivered, OrderStatus::Confirmed, false],
    'delivered → preparing' => [OrderStatus::Delivered, OrderStatus::Preparing, false],
    'delivered → out for delivery' => [OrderStatus::Delivered, OrderStatus::OutForDelivery, false],
    'delivered → delivered' => [OrderStatus::Delivered, OrderStatus::Delivered, false],
    'delivered → cancelled' => [OrderStatus::Delivered, OrderStatus::Cancelled, false],
    'cancelled → pending' => [OrderStatus::Cancelled, OrderStatus::Pending, false],
    'cancelled → confirmed' => [OrderStatus::Cancelled, OrderStatus::Confirmed, false],
    'cancelled → preparing' => [OrderStatus::Cancelled, OrderStatus::Preparing, false],
    'cancelled → out for delivery' => [OrderStatus::Cancelled, OrderStatus::OutForDelivery, false],
    'cancelled → delivered' => [OrderStatus::Cancelled, OrderStatus::Delivered, false],
    'cancelled → cancelled' => [OrderStatus::Cancelled, OrderStatus::Cancelled, false],
]);

test('D9 the delivery address is editable only before out for delivery', function (OrderStatus $status, bool $editable): void {
    expect($status->allowsAddressEdit())->toBe($editable);
})->with([
    'pending' => [OrderStatus::Pending, true],
    'confirmed' => [OrderStatus::Confirmed, true],
    'preparing' => [OrderStatus::Preparing, true],
    'out for delivery' => [OrderStatus::OutForDelivery, false],
    'delivered' => [OrderStatus::Delivered, false],
    'cancelled' => [OrderStatus::Cancelled, false],
]);

test('BR-O02 who cancelled maps to the history actor', function (CancelledBy $by, ActorType $actor): void {
    expect($by->actorType())->toBe($actor);
})->with([
    'customer' => [CancelledBy::Customer, ActorType::Customer],
    'merchant' => [CancelledBy::TenantUser, ActorType::TenantUser],
]);
