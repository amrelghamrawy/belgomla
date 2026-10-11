<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\RelationType;

test('every catalog enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()))
        ->and(array_filter($labels, fn (string $label): bool => $label === ''))->toBe([]);
})->with([ArchiveStatus::class, ProductStatus::class, FieldType::class, RelationType::class, CategoryKind::class]);

test('BR-PR10 the reference price is the cover price for books and the retail price for every other kind', function (CategoryKind $kind, string $label): void {
    expect($kind->referencePriceLabel())->toBe($label);
})->with([
    'books' => [CategoryKind::Books, 'سعر الغلاف'],
    'stationery' => [CategoryKind::Stationery, 'سعر القطاعي'],
    'toys' => [CategoryKind::Toys, 'سعر القطاعي'],
    'general' => [CategoryKind::General, 'سعر القطاعي'],
]);

test('BR-P04 product status transitions', function (ProductStatus $from, ProductStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'active to inactive' => [ProductStatus::Active, ProductStatus::Inactive, true],
    'inactive to active' => [ProductStatus::Inactive, ProductStatus::Active, true],
    'coming soon to active' => [ProductStatus::ComingSoon, ProductStatus::Active, true],
    'coming soon to inactive' => [ProductStatus::ComingSoon, ProductStatus::Inactive, true],
    'active to coming soon' => [ProductStatus::Active, ProductStatus::ComingSoon, false],
    'inactive to coming soon' => [ProductStatus::Inactive, ProductStatus::ComingSoon, false],
    'active to active' => [ProductStatus::Active, ProductStatus::Active, false],
    'inactive to inactive' => [ProductStatus::Inactive, ProductStatus::Inactive, false],
    'coming soon to coming soon' => [ProductStatus::ComingSoon, ProductStatus::ComingSoon, false],
]);

test('BR-P04 inactive products are the only ones hidden from the store', function (): void {
    expect(ProductStatus::visibleOnStore())->toBe([ProductStatus::Active, ProductStatus::ComingSoon])
        ->and(ProductStatus::Inactive->isVisibleOnStore())->toBeFalse();
});

test('BR-P08 only select and boolean fields are filterable', function (FieldType $type, bool $filterable): void {
    expect($type->isFilterable())->toBe($filterable);
})->with([
    'text' => [FieldType::Text, false],
    'number' => [FieldType::Number, false],
    'select' => [FieldType::Select, true],
    'boolean' => [FieldType::Boolean, true],
]);
