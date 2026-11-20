<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function verticalsMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_20_100000_create_verticals.php');

    return $migration instanceof Migration ? $migration : throw new RuntimeException('not a migration');
}

/**
 * @return list<string> option labels of a libraries template, in order
 */
function templateOptions(string $key): array
{
    return array_values(DB::table('vertical_field_template_options')
        ->join('vertical_field_templates', 'vertical_field_templates.id', '=', 'vertical_field_template_options.template_id')
        ->where('vertical_field_templates.key', $key)
        ->orderBy('vertical_field_template_options.sort_order')
        ->pluck('vertical_field_template_options.label')
        ->all());
}

test('D108 c every tenant, whatever its old vertical, ends in libraries and the old column is gone', function (): void {
    verticalsMigration()->down();
    foreach (['school-books', 'stationery', 'hardware', 'none'] as $index => $old) {
        DB::table('tenants')->insert(['subdomain' => "old{$index}", 'name' => 'متجر', 'status' => 'active', 'vertical' => $old, 'created_at' => now(), 'updated_at' => now()]);
    }

    verticalsMigration()->up();

    $libraries = DB::table('verticals')->where('key', 'libraries')->value('id');

    expect(DB::table('tenants')->count())->toBe(5)
        ->and(DB::table('tenants')->where('vertical_id', $libraries)->count())->toBe(5)
        ->and(Schema::hasColumn('tenants', 'vertical'))->toBeFalse();
});

test('D108 b a tenant cannot be stored without a vertical', function (): void {
    DB::table('tenants')->insert(['subdomain' => 'orphan', 'name' => 'متجر', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
})->throws(QueryException::class);

test('D108 c the only vertical is «مكتبات», active', function (): void {
    expect(DB::table('verticals')->get(['key', 'name', 'status'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['key' => 'libraries', 'name' => 'مكتبات', 'status' => 'active']]);
});

test('D109 a e libraries has the sections كتب خارجية, أدوات مكتبية and ألعاب with their reference price labels', function (): void {
    $sections = DB::table('vertical_sections')->orderBy('sort_order')->get(['key', 'name', 'reference_price_label', 'icon', 'status'])
        ->map(fn (object $row): array => (array) $row)->all();

    expect($sections)->toBe([
        ['key' => 'books', 'name' => 'كتب خارجية', 'reference_price_label' => 'سعر الغلاف', 'icon' => 'book-open', 'status' => 'active'],
        ['key' => 'stationery', 'name' => 'أدوات مكتبية', 'reference_price_label' => 'سعر القطاعي', 'icon' => 'pencil', 'status' => 'active'],
        ['key' => 'toys', 'name' => 'ألعاب', 'reference_price_label' => 'سعر القطاعي', 'icon' => 'puzzle', 'status' => 'active'],
    ]);
});

test('BR-P09 the templates are the three old files: school-books in books, stationery in stationery, hardware material in none', function (): void {
    $templates = DB::table('vertical_field_templates')
        ->leftJoin('vertical_sections', 'vertical_sections.id', '=', 'vertical_field_templates.vertical_section_id')
        ->orderBy('vertical_field_templates.id')
        ->get(['vertical_field_templates.key', 'vertical_sections.key as section', 'vertical_field_templates.label', 'vertical_field_templates.filter_order'])
        ->map(fn (object $row): array => (array) $row)->all();

    expect($templates)->toBe([
        ['key' => 'grade', 'section' => 'books', 'label' => 'السنة الدراسية', 'filter_order' => 1],
        ['key' => 'subject', 'section' => 'books', 'label' => 'المادة', 'filter_order' => 2],
        ['key' => 'publisher', 'section' => 'books', 'label' => 'الشركة', 'filter_order' => 3],
        ['key' => 'term', 'section' => 'books', 'label' => 'الترم', 'filter_order' => 4],
        ['key' => 'track', 'section' => 'books', 'label' => 'المسار', 'filter_order' => 5],
        ['key' => 'edition_type', 'section' => 'books', 'label' => 'نوع النسخة', 'filter_order' => 6],
        ['key' => 'academic_year', 'section' => 'books', 'label' => 'العام الدراسي', 'filter_order' => 7],
        ['key' => 'brand', 'section' => 'stationery', 'label' => 'الماركة', 'filter_order' => 1],
        ['key' => 'color', 'section' => 'stationery', 'label' => 'اللون', 'filter_order' => 2],
        ['key' => 'size', 'section' => 'stationery', 'label' => 'المقاس', 'filter_order' => 3],
        ['key' => 'material', 'section' => null, 'label' => 'الخامة', 'filter_order' => 2],
    ])
        ->and(DB::table('vertical_field_templates')->where('type', 'select')->where('is_filterable', true)->where('is_required', false)->count())->toBe(11);
});

test('BR-P09 the template options are the old files\' options, in order', function (string $key, array $options): void {
    expect(templateOptions($key))->toBe($options);
})->with([
    'grade' => ['grade', ['KG1', 'KG2', 'أولى ابتدائي', 'ثانية ابتدائي', 'ثالثة ابتدائي', 'رابعة ابتدائي', 'خامسة ابتدائي', 'سادسة ابتدائي', 'أولى إعدادي', 'ثانية إعدادي', 'ثالثة إعدادي', 'أولى ثانوي', 'ثانية ثانوي', 'ثالثة ثانوي']],
    'subject' => ['subject', ['عربي', 'إنجليزي', 'رياضيات', 'علوم', 'دراسات', 'دين', 'فرنساوي', 'فيزياء', 'كيمياء', 'أحياء', 'فلسفة', 'تاريخ', 'جغرافيا', 'حاسب آلي']],
    'publisher' => ['publisher', ['الأضواء', 'المعلم', 'كراسة الأول', 'سلاح التلميذ', 'الامتحان', 'بت بايت', 'جيم']],
    'term' => ['term', ['الترم الأول', 'الترم الثاني', 'كامل العام']],
    'track' => ['track', ['عربي', 'لغات']],
    'edition_type' => ['edition_type', ['الكتاب الأساسي', 'المراجعة النهائية', 'كتاب الامتحانات', 'ملحق المراجعة والامتحانات']],
    'academic_year' => ['academic_year', ['2025/2026', '2026/2027']],
    'brand' => ['brand', []],
    'color' => ['color', ['أزرق', 'أسود', 'أحمر', 'أخضر', 'أبيض', 'ألوان متعددة']],
    'size' => ['size', []],
    'material' => ['material', []],
]);

test('the rollback restores tenants.vertical as none and drops the platform tables', function (): void {
    verticalsMigration()->down();

    expect(DB::table('tenants')->where('id', $this->tenant->id)->value('vertical'))->toBe('none')
        ->and(Schema::hasColumn('tenants', 'vertical_id'))->toBeFalse()
        ->and(Schema::hasTable('verticals'))->toBeFalse()
        ->and(Schema::hasTable('vertical_field_template_options'))->toBeFalse();

    verticalsMigration()->up();
});
