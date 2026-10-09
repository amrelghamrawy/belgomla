<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Services\VerticalTemplates;
use App\Modules\Tenancy\Enums\Vertical;

test('BR-P09 the school-books template matches the documented table', function (): void {
    $fields = app(VerticalTemplates::class)->fieldsFor(Vertical::SchoolBooks);

    expect(array_column($fields, 'key'))->toBe(['grade', 'subject', 'publisher', 'term', 'track', 'edition_type', 'academic_year'])
        ->and(array_column($fields, 'label'))->toBe(['السنة الدراسية', 'المادة', 'الشركة', 'الترم', 'المسار', 'نوع النسخة', 'العام الدراسي'])
        ->and(array_unique(array_map(fn (array $f): FieldType => $f['type'], $fields), SORT_REGULAR))->toBe([FieldType::Select])
        ->and(array_column($fields, 'filter_order'))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($fields[3]['options'])->toBe(['الترم الأول', 'الترم الثاني', 'كامل العام'])
        ->and($fields[4]['options'])->toBe(['عربي', 'لغات'])
        ->and($fields[6]['options'])->toBe(['2025/2026', '2026/2027']);
});

test('a vertical without a template file has no fields', function (): void {
    expect(app(VerticalTemplates::class)->fieldsFor(Vertical::None))->toBe([]);
});

test('malformed template files fail loudly', function (string $contents, string $message): void {
    $dir = sys_get_temp_dir().'/belgomla-verticals-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/stationery.php', $contents);

    expect(fn (): array => new VerticalTemplates($dir)->fieldsFor(Vertical::Stationery))->toThrow(RuntimeException::class, $message);
})->with([
    'not an array' => ['<?php return "x";', 'must return an array'],
    'field without key' => ['<?php return [["label" => "x", "type" => "text"]];', 'without key/label'],
    'unknown type' => ['<?php return [["key" => "k", "label" => "x", "type" => "date"]];', 'unknown type'],
]);

test('optional template keys get defaults and non-string options are dropped', function (): void {
    $dir = sys_get_temp_dir().'/belgomla-verticals-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/hardware.php', '<?php return [["key" => "k", "label" => "x", "type" => "select", "options" => ["a", 5]]];');

    expect(new VerticalTemplates($dir)->fieldsFor(Vertical::Hardware))->toBe([[
        'key' => 'k', 'label' => 'x', 'type' => FieldType::Select, 'is_filterable' => false,
        'filter_order' => 0, 'sort_order' => 0, 'options' => ['a'],
    ]]);
});
