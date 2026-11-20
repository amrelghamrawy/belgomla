<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryIcon;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Section;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BR-P16, D109: a store's sections. Every store is made with the sections of its vertical plus the fallback «عام»
| (TenantFactory runs the same provisioning as a real store), so the test store has books, stationery, toys, general.
*/

test('to array', function (): void {
    $section = $this->inTenant(fn () => Section::factory()->create()->fresh());

    expect(array_keys($section->toArray()))->toBe([
        'id', 'tenant_id', 'vertical_section_id', 'key', 'name', 'reference_price_label', 'icon', 'is_fallback', 'status',
        'sort_order', 'created_at', 'updated_at',
    ]);
});

test('casts', function (): void {
    $section = $this->inTenant(fn () => Section::factory()->archived()->create(['icon' => 'puzzle', 'sort_order' => '7'])->fresh());

    expect($section->icon)->toBe(CategoryIcon::Puzzle)
        ->and($section->status)->toBe(ArchiveStatus::Archived)
        ->and($section->is_fallback)->toBeFalse()
        ->and($section->sort_order)->toBe(7);
});

test('D109 a section made by the store defaults to active, not the fallback, and the retail price label', function (): void {
    $section = $this->inTenant(fn () => Section::query()->create(['key' => 'gifts', 'name' => 'هدايا']));

    expect($section->status)->toBe(ArchiveStatus::Active)
        ->and($section->is_fallback)->toBeFalse()
        ->and($section->reference_price_label)->toBe('سعر القطاعي')
        ->and($section->vertical_section_id)->toBeNull();
});

test('D109 b c a new store has the sections of its vertical and one fallback «عام»', function (): void {
    $sections = $this->inTenant(fn (): array => Section::query()->orderBy('sort_order')->get()
        ->map(fn (Section $s): array => [$s->key, $s->name, $s->reference_price_label, $s->icon?->value, $s->is_fallback])->all());

    expect($sections)->toBe([
        ['books', 'كتب خارجية', 'سعر الغلاف', 'book-open', false],
        ['stationery', 'أدوات مكتبية', 'سعر القطاعي', 'pencil', false],
        ['toys', 'ألعاب', 'سعر القطاعي', 'puzzle', false],
        ['general', 'عام', 'سعر القطاعي', 'package', true],
    ]);
});

test('a section lists its root categories', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->inSection('toys')->create());

    expect($this->inTenant(fn () => Section::query()->where('key', 'toys')->sole()->categories->pluck('id')->all()))->toBe([$category->id]);
});

test('BR-T03 creating a section without a tenant in context throws', function (): void {
    Section::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 section queries without a tenant in context return nothing', function (): void {
    app(TenantContext::class)->clear();

    expect(Section::query()->count())->toBe(0);
});

test('BR-T03 section queries in context never see another tenant', function (): void {
    $other = $this->otherTenant();
    $this->inTenant(fn () => Section::factory()->create(['key' => 'gifts']), $other);

    expect($this->inTenant(fn () => Section::query()->where('key', 'gifts')->count()))->toBe(0);
});

test('the database guards a store sections', function (array $values, string $constraint): void {
    expect(fn () => DB::table('sections')->insert([
        'tenant_id' => $this->tenant->id, 'key' => 'gifts', 'name' => 'هدايا', 'created_at' => now(), 'updated_at' => now(), ...$values,
    ]))->toThrow(QueryException::class, $constraint);
})->with([
    'a key used twice in a store' => [['key' => 'books'], 'sections_tenant_id_key_unique'],
    'a name used twice in a store' => [['name' => 'ألعاب'], 'sections_tenant_id_name_unique'],
    'a second fallback' => [['is_fallback' => true], 'sections_one_fallback'],
    'an archived fallback' => [['key' => 'x', 'is_fallback' => true, 'status' => 'archived'], 'sections_fallback_active'],
    'an unknown status' => [['status' => 'hidden'], 'sections_status_known'],
]);

test('two stores may use the same section key and name', function (): void {
    expect(DB::table('sections')->where('key', 'books')->where('name', 'كتب خارجية')->count())->toBe(1);

    $this->otherTenant();

    expect(DB::table('sections')->where('key', 'books')->where('name', 'كتب خارجية')->count())->toBe(2);
});
