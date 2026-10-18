<?php

declare(strict_types=1);

use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Reports\Enums\SalesGrouping;

test('every collections and reports enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))
        ->and(array_filter($labels, fn (string $label): bool => $label === ''))->toBe([]);
})->with([CollectionMethod::class, CollectionKind::class, SalesGrouping::class]);

test('BR-C02 cash is the only collection method in V1', function (): void {
    expect(CollectionMethod::toArray())->toBe(['cash' => 'نقدي']);
});

test('BR-R04 a collection is a payment or a refund', function (): void {
    expect(CollectionKind::toArray())->toBe(['payment' => 'تحصيل', 'refund' => 'رد']);
});

test('D5 the sales report groups by day, week or month', function (): void {
    expect(array_keys(SalesGrouping::toArray()))->toBe(['day', 'week', 'month']);
});

test('BR-C03 the collection status is derived from the collected amount against the total', function (string $collected, string $total, CollectionStatus $status): void {
    expect(CollectionStatus::for($collected, $total))->toBe($status);
})->with([
    'nothing collected' => ['0.00', '500.00', CollectionStatus::Unpaid],
    'a piaster collected' => ['0.01', '500.00', CollectionStatus::PartiallyCollected],
    'a piaster short' => ['499.99', '500.00', CollectionStatus::PartiallyCollected],
    'exactly the total' => ['500.00', '500.00', CollectionStatus::Collected],
    'more than the total' => ['500.01', '500.00', CollectionStatus::Collected],
    'BR-R05 an order returned in full owes nothing' => ['0.00', '0.00', CollectionStatus::Collected],
    'BR-R06 a voided refund leaves more collected than the net total' => ['500.00', '300.00', CollectionStatus::Collected],
]);
