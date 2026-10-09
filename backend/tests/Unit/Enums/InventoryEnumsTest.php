<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\MovementType;

test('every inventory enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))
        ->and(array_filter($labels, fn (string $label): bool => $label === ''))->toBe([]);
})->with([MovementType::class]);

test('BR-INV06 the movement types match the domain model, return reserved for V2', function (): void {
    expect(array_map(fn (MovementType $type): string => $type->value, MovementType::cases()))->toBe(['opening', 'adjustment', 'sale', 'return']);
});
