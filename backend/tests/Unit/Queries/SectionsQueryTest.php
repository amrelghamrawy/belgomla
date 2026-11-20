<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Resources\SectionResource;
use App\Modules\Catalog\Models\Section;
use App\Modules\Catalog\Queries\SectionsQuery;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D109 the store sections come in display order, archived included, then by id on a tie', function (): void {
    $gifts = Section::factory()->create(['key' => 'gifts', 'sort_order' => 2]);
    Section::factory()->archived()->create(['key' => 'old', 'sort_order' => 9]);

    expect(app(SectionsQuery::class)->get()->pluck('key')->all())->toBe(['books', 'stationery', 'gifts', 'toys', 'general', 'old'])
        ->and($gifts->sort_order)->toBe(2);
});

test('D109 e the section names by key, as the store named them', function (): void {
    Section::query()->where('key', 'stationery')->update(['name' => 'أدوات المدرسة']);

    expect(app(SectionsQuery::class)->namesByKey())->toBe([
        'books' => 'كتب خارجية', 'stationery' => 'أدوات المدرسة', 'toys' => 'ألعاب', 'general' => 'عام',
    ]);
});

test('BR-T04 another store sections are never listed', function (): void {
    $this->inTenant(fn () => Section::factory()->create(['key' => 'gifts']), $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);

    expect(app(SectionsQuery::class)->get())->toHaveCount(4);
});

test('the section resource key set', function (): void {
    $books = Section::query()->where('key', 'books')->sole();

    expect(new SectionResource($books)->toArray(request()))
        ->toBe(['id' => $books->id, 'name' => 'كتب خارجية', 'reference_price_label' => 'سعر الغلاف', 'icon' => 'book-open']);
});

test('the section resource has a null icon when the section has none', function (): void {
    $section = Section::factory()->create(['name' => 'هدايا', 'icon' => null]);

    expect(new SectionResource($section)->toArray(request())['icon'])->toBeNull();
});
