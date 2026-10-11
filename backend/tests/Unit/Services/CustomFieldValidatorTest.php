<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Exceptions\InvalidCustomFields;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Services\CustomFieldValidator;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);

    $this->grade = ProductFieldDefinition::factory()->select()->create(['key' => 'grade']);
    $this->first = ProductFieldOption::factory()->for($this->grade, 'definition')->create(['label' => 'أولى']);
    $this->retired = ProductFieldOption::factory()->for($this->grade, 'definition')->archived()->create(['label' => 'قديم']);
    ProductFieldDefinition::factory()->create(['key' => 'isbn']);
    ProductFieldDefinition::factory()->number()->create(['key' => 'pages']);
    ProductFieldDefinition::factory()->boolean()->create(['key' => 'colored']);
    ProductFieldDefinition::factory()->archived()->create(['key' => 'old_code']);

    $this->validator = app(CustomFieldValidator::class);
});

/**
 * @param  array<array-key, mixed>  $input
 * @param  array<string, mixed>  $current
 * @return array<string, string>
 */
function customFieldErrors(CustomFieldValidator $validator, array $input, array $current = [], CategoryKind $kind = CategoryKind::General): array
{
    try {
        $validator->validate($input, $kind, $current);
    } catch (InvalidCustomFields $e) {
        return array_map(fn (array $messages): string => $messages[0], $e->details ?? []);
    }

    return [];
}

test('BR-P08 values are normalised per type and sorted by key', function (): void {
    $values = $this->validator->validate([
        'pages' => '120', 'grade' => (string) $this->first->id, 'isbn' => '  978-1 ', 'colored' => 'true',
    ], CategoryKind::General);

    expect($values)->toBe(['colored' => true, 'grade' => $this->first->id, 'isbn' => '978-1', 'pages' => 120]);
});

test('BR-P08 a select stores the option id', function (): void {
    expect($this->validator->validate(['grade' => $this->first->id], CategoryKind::General))->toBe(['grade' => $this->first->id]);
});

test('numbers keep decimals and whole floats become integers', function (mixed $input, int|float $expected): void {
    expect($this->validator->validate(['pages' => $input], CategoryKind::General)['pages'])->toBe($expected);
})->with([
    'decimal string' => ['12.5', 12.5],
    'whole float' => [12.0, 12],
    'integer' => [7, 7],
    'decimal float' => [0.25, 0.25],
]);

test('booleans accept the usual encodings', function (mixed $input, bool $expected): void {
    expect($this->validator->validate(['colored' => $input], CategoryKind::General)['colored'])->toBe($expected);
})->with([
    'true' => [true, true], '1' => ['1', true], 'int 1' => [1, true], 'string true' => ['true', true],
    'false' => [false, false], '0' => ['0', false], 'int 0' => [0, false], 'string false' => ['false', false],
]);

test('null and empty values clear the field', function (): void {
    expect($this->validator->validate(['isbn' => '', 'pages' => null], CategoryKind::General))->toBe([]);
});

test('BR-P08 invalid values are reported per key', function (array $input, string $key, string $message): void {
    expect(customFieldErrors($this->validator, $input))->toHaveKey("custom_fields.{$key}", $message);
})->with([
    'unknown key' => [['brand' => 'x'], 'brand', 'حقل غير معروف'],
    'text that is not a string' => [['isbn' => ['a']], 'isbn', 'يجب أن يكون نصًا لا يزيد عن 500 حرف'],
    'text too long' => [['isbn' => str_repeat('a', 501)], 'isbn', 'يجب أن يكون نصًا لا يزيد عن 500 حرف'],
    'number that is not numeric' => [['pages' => 'many'], 'pages', 'يجب أن يكون رقمًا'],
    'boolean that is not boolean' => [['colored' => 'maybe'], 'colored', 'يجب أن تكون القيمة نعم أو لا'],
    'select with a non-id' => [['grade' => 'first'], 'grade', 'الاختيار غير موجود في هذا الحقل'],
    'select with an unknown id' => [['grade' => 999999], 'grade', 'الاختيار غير موجود في هذا الحقل'],
    'archived definition' => [['old_code' => 'x'], 'old_code', 'هذا الحقل مؤرشف'],
]);

test('BR-P08 an option of another field is not accepted', function (): void {
    $other = ProductFieldOption::factory()->create();

    expect(customFieldErrors($this->validator, ['grade' => $other->id]))->toHaveKey('custom_fields.grade');
});

test('BR-P08 an archived option cannot be newly chosen', function (): void {
    expect(customFieldErrors($this->validator, ['grade' => $this->retired->id]))->toBe(['custom_fields.grade' => 'هذا الاختيار مؤرشف']);
});

test('BR-P08 an archived option the product already holds is kept', function (): void {
    expect($this->validator->validate(['grade' => $this->retired->id], CategoryKind::General, ['grade' => $this->retired->id]))->toBe(['grade' => $this->retired->id]);
});

test('BR-P08 a required field must be present', function (): void {
    ProductFieldDefinition::factory()->required()->create(['key' => 'publisher']);

    expect(customFieldErrors($this->validator, []))->toBe(['custom_fields.publisher' => 'هذا الحقل مطلوب']);
});

test('a required field that is archived is not required', function (): void {
    ProductFieldDefinition::factory()->required()->archived()->create(['key' => 'publisher']);

    expect($this->validator->validate([], CategoryKind::General))->toBe([]);
});

test('BR-P08 values of archived fields are carried over from the stored map', function (): void {
    expect($this->validator->validate(['isbn' => '1'], CategoryKind::General, ['old_code' => 'X-1', 'isbn' => '0']))->toBe(['isbn' => '1', 'old_code' => 'X-1']);
});

test('an archived field may be resent with its unchanged value', function (): void {
    expect($this->validator->validate(['old_code' => 'X-1'], CategoryKind::General, ['old_code' => 'X-1']))->toBe(['old_code' => 'X-1']);
});

test('D69 a field bound to another section cannot be sent for the product', function (): void {
    ProductFieldDefinition::factory()->forKind(CategoryKind::Books)->create(['key' => 'publisher']);

    expect(customFieldErrors($this->validator, ['publisher' => 'x']))->toBe(['custom_fields.publisher' => 'هذا الحقل لا ينطبق على قسم هذا المنتج']);
});

test('D69 a field bound to the product section is accepted and a section-less field always is', function (): void {
    ProductFieldDefinition::factory()->forKind(CategoryKind::Toys)->create(['key' => 'age']);

    expect($this->validator->validate(['age' => '3+', 'isbn' => '1'], CategoryKind::Toys))->toBe(['age' => '3+', 'isbn' => '1']);
});

test('D69 a required field of another section is not required', function (): void {
    ProductFieldDefinition::factory()->required()->forKind(CategoryKind::Books)->create(['key' => 'publisher']);

    expect($this->validator->validate([], CategoryKind::Toys))->toBe([]);
});

test('D69 a required field of the product section is required', function (): void {
    ProductFieldDefinition::factory()->required()->forKind(CategoryKind::Toys)->create(['key' => 'age']);

    expect(customFieldErrors($this->validator, [], kind: CategoryKind::Toys))->toBe(['custom_fields.age' => 'هذا الحقل مطلوب']);
});

test('D69 values of fields of another section are carried over when a product moves', function (): void {
    ProductFieldDefinition::factory()->forKind(CategoryKind::Books)->create(['key' => 'publisher']);

    expect($this->validator->validate(['isbn' => '1'], CategoryKind::Toys, ['publisher' => 'الأضواء']))->toBe(['isbn' => '1', 'publisher' => 'الأضواء']);
});

test('D69 a field of another section may be resent with its unchanged value', function (): void {
    ProductFieldDefinition::factory()->forKind(CategoryKind::Books)->create(['key' => 'publisher']);

    expect($this->validator->validate(['publisher' => 'الأضواء'], CategoryKind::Toys, ['publisher' => 'الأضواء']))->toBe(['publisher' => 'الأضواء']);
});

test('every error is reported at once', function (): void {
    expect(customFieldErrors($this->validator, ['brand' => 'x', 'pages' => 'many']))->toHaveKeys(['custom_fields.brand', 'custom_fields.pages']);
});

test('definitions of another tenant are unknown here', function (): void {
    $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['key' => 'brand']), $this->otherTenant());

    expect(customFieldErrors($this->validator, ['brand' => 'x']))->toHaveKey('custom_fields.brand');
});
