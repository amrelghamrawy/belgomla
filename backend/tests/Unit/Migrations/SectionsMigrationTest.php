<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V3 Task 02 (D109, BR-P16): the sections migration on a catalog in the kind shape. It is rolled back first, inside
| the test's transaction (PostgreSQL DDL is transactional), a catalog with every kind is written with plain inserts,
| then it runs forward (and back) and the data is checked. Nothing outlives the test. The store is in the libraries
| vertical (D108 c): books «كتب خارجية», stationery «أدوات مكتبية», toys «ألعاب»; general goes to the fallback «عام».
*/

function sectionsMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_20_100100_create_sections.php');

    return $migration instanceof Migration ? $migration : throw new RuntimeException('not a migration');
}

/**
 * @param  array<string, mixed>  $values
 */
function sectionsInsert(string $table, array $values): int
{
    $id = DB::table($table)->insertGetId([...$values, 'created_at' => now(), 'updated_at' => now()]);

    return is_int($id) ? $id : (int) $id;
}

/** The section name of a row, after the migration ran forward. */
function sectionNameOf(string $table, int $id): ?string
{
    $name = DB::table($table)->leftJoin('sections', 'sections.id', '=', "{$table}.section_id")->where("{$table}.id", $id)->value('sections.name');

    return is_string($name) ? $name : null;
}

beforeEach(function (): void {
    sectionsMigration()->down();

    $tenant = $this->tenant->id;
    $this->categories = [];
    foreach (['books', 'stationery', 'toys', 'general'] as $kind) {
        $this->categories[$kind] = sectionsInsert('categories', ['tenant_id' => $tenant, 'name' => "قسم {$kind}", 'slug' => $kind, 'path' => '/', 'kind' => $kind]);
    }
    $this->child = sectionsInsert('categories', ['tenant_id' => $tenant, 'parent_id' => $this->categories['books'], 'name' => 'أولى إعدادي', 'slug' => 'prep-1', 'path' => '/', 'kind' => 'books']);

    $this->fields = [
        'grade' => sectionsInsert('product_field_definitions', ['tenant_id' => $tenant, 'key' => 'grade', 'label' => 'الصف', 'type' => 'select', 'kind' => 'books']),
        'age' => sectionsInsert('product_field_definitions', ['tenant_id' => $tenant, 'key' => 'age', 'label' => 'السن', 'type' => 'select', 'kind' => 'toys']),
        'material' => sectionsInsert('product_field_definitions', ['tenant_id' => $tenant, 'key' => 'material', 'label' => 'الخامة', 'type' => 'select', 'kind' => null]),
        'origin' => sectionsInsert('product_field_definitions', ['tenant_id' => $tenant, 'key' => 'origin', 'label' => 'بلد المنشأ', 'type' => 'text', 'kind' => 'general']),
    ];
});

test('D109 b c every store gets the sections of its vertical and one fallback «عام»', function (): void {
    sectionsMigration()->up();

    $sections = DB::table('sections')->where('tenant_id', $this->tenant->id)->orderBy('sort_order')->get()
        ->map(fn (object $s): array => [$s->key, $s->name, $s->reference_price_label, $s->icon, (bool) $s->is_fallback, $s->vertical_section_id !== null])
        ->all();

    expect($sections)->toBe([
        ['books', 'كتب خارجية', 'سعر الغلاف', 'book-open', false, true],
        ['stationery', 'أدوات مكتبية', 'سعر القطاعي', 'pencil', false, true],
        ['toys', 'ألعاب', 'سعر القطاعي', 'puzzle', false, true],
        ['general', 'عام', 'سعر القطاعي', 'package', true, false],
    ]);
});

test('D109 every category kind lands in the section of that key, the subtree too, and general in the fallback', function (): void {
    sectionsMigration()->up();

    expect(sectionNameOf('categories', $this->categories['books']))->toBe('كتب خارجية')
        ->and(sectionNameOf('categories', $this->categories['stationery']))->toBe('أدوات مكتبية')
        ->and(sectionNameOf('categories', $this->categories['toys']))->toBe('ألعاب')
        ->and(sectionNameOf('categories', $this->categories['general']))->toBe('عام')
        ->and(sectionNameOf('categories', $this->child))->toBe('كتب خارجية');
});

test('D69 D109 field kinds land in their sections and a field of every section stays without one', function (): void {
    sectionsMigration()->up();

    expect(sectionNameOf('product_field_definitions', $this->fields['grade']))->toBe('كتب خارجية')
        ->and(sectionNameOf('product_field_definitions', $this->fields['age']))->toBe('ألعاب')
        ->and(sectionNameOf('product_field_definitions', $this->fields['material']))->toBeNull()
        ->and(sectionNameOf('product_field_definitions', $this->fields['origin']))->toBe('عام');
});

test('D110 b fields are linked to the vertical template of the same key, the others to none', function (): void {
    sectionsMigration()->up();

    $template = fn (string $key): mixed => DB::table('vertical_field_templates')->join('verticals', 'verticals.id', '=', 'vertical_field_templates.vertical_id')
        ->where('verticals.key', 'libraries')->where('vertical_field_templates.key', $key)->value('vertical_field_templates.id');

    expect(DB::table('product_field_definitions')->where('id', $this->fields['grade'])->value('template_id'))->toBe($template('grade'))
        ->and(DB::table('product_field_definitions')->where('id', $this->fields['material'])->value('template_id'))->toBe($template('material'))
        ->and(DB::table('product_field_definitions')->where('id', $this->fields['age'])->value('template_id'))->toBeNull();
});

test('D109 the kind columns are gone and a category cannot be left without a section', function (): void {
    sectionsMigration()->up();

    expect(Schema::hasColumn('categories', 'kind'))->toBeFalse()
        ->and(Schema::hasColumn('product_field_definitions', 'kind'))->toBeFalse()
        ->and(DB::table('categories')->whereNull('section_id')->count())->toBe(0);
});

test('a store without vertical sections gets only the fallback, and every kind goes there', function (): void {
    $vertical = sectionsInsert('verticals', ['key' => 'empty', 'name' => 'فارغ', 'status' => 'active']);
    DB::table('tenants')->where('id', $this->tenant->id)->update(['vertical_id' => $vertical]);

    sectionsMigration()->up();

    expect(DB::table('sections')->where('tenant_id', $this->tenant->id)->pluck('key')->all())->toBe(['general'])
        ->and(sectionNameOf('categories', $this->categories['books']))->toBe('عام')
        ->and(sectionNameOf('product_field_definitions', $this->fields['age']))->toBe('عام');
});

test('the rollback writes the section keys back into kind and drops the sections', function (): void {
    sectionsMigration()->up();
    $gifts = sectionsInsert('sections', ['tenant_id' => $this->tenant->id, 'key' => 'gifts', 'name' => 'هدايا']);
    DB::table('categories')->where('id', $this->categories['toys'])->update(['section_id' => $gifts]);

    sectionsMigration()->down();

    expect(DB::table('categories')->where('id', $this->categories['books'])->value('kind'))->toBe('books')
        ->and(DB::table('categories')->where('id', $this->child)->value('kind'))->toBe('books')
        ->and(DB::table('categories')->where('id', $this->categories['toys'])->value('kind'))->toBe('general')
        ->and(DB::table('product_field_definitions')->where('id', $this->fields['age'])->value('kind'))->toBe('toys')
        ->and(DB::table('product_field_definitions')->where('id', $this->fields['material'])->value('kind'))->toBeNull()
        ->and(Schema::hasTable('sections'))->toBeFalse()
        ->and(Schema::hasColumn('categories', 'section_id'))->toBeFalse();

    sectionsMigration()->up();
});
