<?php

declare(strict_types=1);

use App\Modules\Platform\Enums\VerticalStatus;

test('every enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));

    foreach ($labels as $label) {
        expect($label)->toBeString()->not->toBe('');
    }
})->with([
    VerticalStatus::class,
]);

test('vertical statuses read in Arabic', function (): void {
    expect(VerticalStatus::toArray())->toBe(['active' => 'نشط', 'archived' => 'مؤرشف']);
});
