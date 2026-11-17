<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Enums\MovementType;

test('every inventory enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))
        ->and(array_filter($labels, fn (string $label): bool => $label === ''))->toBe([]);
})->with([MovementType::class, AdjustmentReason::class]);

test('BR-INV06 BR-INV09 the movement types match the domain model', function (): void {
    expect(array_map(fn (MovementType $type): string => $type->value, MovementType::cases()))->toBe(['opening', 'adjustment', 'sale', 'return', 'receive']);
});

test('D97 the merchant chooses every reason but the import, and only other needs a note', function (): void {
    expect(AdjustmentReason::chosenByMerchant())->toBe(['stocktake', 'damaged', 'expired', 'recording_error', 'found', 'other'])
        ->and(array_values(array_filter(AdjustmentReason::cases(), fn (AdjustmentReason $reason): bool => $reason->needsNote())))->toBe([AdjustmentReason::Other]);
});

test('D95 e D97 the import reason reads as the text the import always wrote', function (): void {
    expect(AdjustmentReason::Import->label())->toBe('استيراد');
});
