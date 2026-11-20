<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\ProvisionSections;
use App\Modules\Catalog\Models\Section;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Platform\Models\VerticalSection;
use App\Modules\Tenancy\TenantContext;

/*
| D109 b, c, D110 b: a store gets the active sections of its vertical and one fallback «عام», once. The test store
| already went through it when it was made (TenantFactory), with the libraries vertical.
*/

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

function provisionedLibrariesId(): int
{
    return Vertical::query()->where('key', 'libraries')->sole()->id;
}

/**
 * @return list<string>
 */
function sectionKeys(): array
{
    return Section::query()->orderBy('sort_order')->pluck('key')->all();
}

test('D109 running it again creates nothing and returns the store section ids by key', function (): void {
    $before = Section::query()->pluck('id', 'key')->all();

    $map = app(ProvisionSections::class)->handle(provisionedLibrariesId());

    expect(Section::query()->count())->toBe(4)
        ->and($map)->toEqual($before)
        ->and(array_keys($map))->toEqualCanonicalizing(['books', 'stationery', 'toys', 'general']);
});

test('D110 b a section the store renamed or archived is not made again', function (): void {
    Section::query()->where('key', 'books')->update(['name' => 'كتب المدارس']);
    Section::query()->where('key', 'toys')->update(['status' => 'archived']);

    app(ProvisionSections::class)->handle(provisionedLibrariesId());

    expect(Section::query()->count())->toBe(4)
        ->and(Section::query()->where('key', 'books')->sole()->name)->toBe('كتب المدارس')
        ->and(Section::query()->where('key', 'toys')->sole()->status->value)->toBe('archived');
});

test('D110 a section added to the vertical later is given to the store after its own sections', function (): void {
    VerticalSection::factory()->for(Vertical::query()->findOrFail(provisionedLibrariesId()))->create(['key' => 'bags', 'name' => 'شنط', 'sort_order' => 9]);

    app(ProvisionSections::class)->handle(provisionedLibrariesId());

    $bags = Section::query()->where('key', 'bags')->sole();

    expect(sectionKeys())->toBe(['books', 'stationery', 'toys', 'general', 'bags'])
        ->and($bags->name)->toBe('شنط')
        ->and($bags->is_fallback)->toBeFalse();
});

test('D110 an archived vertical section is not given to the store', function (): void {
    VerticalSection::factory()->for(Vertical::query()->findOrFail(provisionedLibrariesId()))->archived()->create(['key' => 'bags']);

    app(ProvisionSections::class)->handle(provisionedLibrariesId());

    expect(Section::query()->where('key', 'bags')->exists())->toBeFalse();
});

test('D110 b a vertical section whose key or name the store already uses is skipped', function (string $key, string $name): void {
    Section::factory()->create(['key' => 'gifts', 'name' => 'هدايا']);
    VerticalSection::factory()->for(Vertical::query()->findOrFail(provisionedLibrariesId()))->create(['key' => $key, 'name' => $name]);

    app(ProvisionSections::class)->handle(provisionedLibrariesId());

    expect(Section::query()->count())->toBe(5);
})->with([
    'same key' => ['gifts', 'هدايا وتغليف'],
    'same name' => ['presents', 'هدايا'],
]);

test('D109 c a store without sections gets the vertical sections in order, then the fallback «عام» last', function (): void {
    Section::query()->delete();
    $vertical = Vertical::factory()->create();
    VerticalSection::factory()->for($vertical)->create(['key' => 'shoes', 'name' => 'أحذية', 'sort_order' => 2]);
    VerticalSection::factory()->for($vertical)->create(['key' => 'bags', 'name' => 'شنط', 'sort_order' => 1]);

    $map = app(ProvisionSections::class)->handle($vertical->id);

    $fallback = Section::query()->where('is_fallback', true)->sole();

    expect(sectionKeys())->toBe(['bags', 'shoes', 'general'])
        ->and(array_keys($map))->toEqualCanonicalizing(['bags', 'shoes', 'general'])
        ->and([$fallback->key, $fallback->name, $fallback->icon?->value, $fallback->reference_price_label])->toBe(['general', 'عام', 'package', 'سعر القطاعي']);
});

test('D109 c no second fallback is made when the store renamed its fallback key', function (): void {
    Section::query()->where('key', 'general')->update(['key' => 'misc']);

    app(ProvisionSections::class)->handle(provisionedLibrariesId());

    expect(Section::query()->where('is_fallback', true)->count())->toBe(1)
        ->and(Section::query()->where('key', 'general')->exists())->toBeFalse();
});
