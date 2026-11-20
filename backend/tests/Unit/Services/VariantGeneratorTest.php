<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductOptionData;
use App\Modules\Catalog\Data\ProductOptionValueData;
use App\Modules\Catalog\Services\VariantGenerator;

function variantOption(string $name, string ...$values): ProductOptionData
{
    return new ProductOptionData(null, $name, array_map(fn (string $value): ProductOptionValueData => new ProductOptionValueData(null, $value), array_values($values)));
}

test('BR-P14 the variants are the cartesian product of the option values, the last option varying fastest', function (): void {
    $drafts = app(VariantGenerator::class)->generate('BAG-1', [variantOption('اللون', 'Red', 'Blue'), variantOption('المقاس', 'S', 'M', 'L')]);

    expect(array_column($drafts, 'option_values'))->toBe([
        ['Red', 'S'], ['Red', 'M'], ['Red', 'L'], ['Blue', 'S'], ['Blue', 'M'], ['Blue', 'L'],
    ])->and(array_column($drafts, 'code'))->toBe(['BAG-1-RED-S', 'BAG-1-RED-M', 'BAG-1-RED-L', 'BAG-1-BLUE-S', 'BAG-1-BLUE-M', 'BAG-1-BLUE-L']);
});

test('BR-P03 an arabic value is slugged by its position in its option so codes stay latin', function (): void {
    $drafts = app(VariantGenerator::class)->generate('PEN-01', [variantOption('اللون', 'أزرق', 'أسود', 'أحمر'), variantOption('المقاس', 'S')]);

    expect(array_column($drafts, 'code'))->toBe(['PEN-01-1-S', 'PEN-01-2-S', 'PEN-01-3-S'])
        ->and(array_column($drafts, 'label'))->toBe(['أزرق / S', 'أسود / S', 'أحمر / S']);
});

test('a value slug keeps latin letters and digits, upper-cased, other runs becoming one dash', function (string $value, int $position, string $slug): void {
    expect(app(VariantGenerator::class)->slug($value, $position))->toBe($slug);
})->with([
    'words' => ['Navy blue', 1, 'NAVY-BLUE'],
    'digits and symbols' => ['  2 x 3 cm ', 2, '2-X-3-CM'],
    'mixed arabic and latin' => ['مقاس XL', 4, 'XL'],
    'arabic only' => ['أزرق', 3, '3'],
]);

test('one option makes one variant per value and no option makes one bare variant', function (): void {
    $generator = app(VariantGenerator::class);

    expect($generator->generate('A', [variantOption('اللون', 'أزرق', 'أسود')]))->toHaveCount(2)
        ->and($generator->generate('A', []))->toBe([['option_values' => [], 'code' => 'A', 'label' => null]]);
});

test('the variant label joins the values and is null without values', function (): void {
    expect(app(VariantGenerator::class)->label(['أزرق', 'M']))->toBe('أزرق / M')
        ->and(app(VariantGenerator::class)->label([]))->toBeNull();
});
