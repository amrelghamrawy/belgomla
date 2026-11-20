<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Section;
use App\Modules\Catalog\Services\SectionResolver;
use App\Modules\Tenancy\TenantContext;

/*
| D109, BR-T04: the section a request names is looked up inside the store in context, by id or by the old kind
| (= the section key, until V3 Task 03). Only an active section of this store can be chosen.
*/

beforeEach(function (): void {
    $this->resolver = app(SectionResolver::class);
});

/**
 * @return array{0: string, 1: array<string, list<string>>}
 */
function sectionResolverError(Closure $call): array
{
    try {
        $call();
    } catch (InvalidCatalogInput $e) {
        return [$e->errorCode, $e->details];
    }

    throw new RuntimeException('No InvalidCatalogInput thrown.');
}

test('D109 nothing named means no section', function (): void {
    expect($this->inTenant(fn () => $this->resolver->chosen(null, null)))->toBeNull();
});

test('D109 a section is found by id, and the id wins over a kind', function (): void {
    $chosen = $this->inTenant(fn () => $this->resolver->chosen($this->sectionId('toys'), 'books'));

    expect($chosen?->key)->toBe('toys');
});

test('D109 the old kind is read as the section key, general being the fallback', function (string $kind, string $name): void {
    expect($this->inTenant(fn () => $this->resolver->chosen(null, $kind))?->name)->toBe($name);
})->with([
    'books' => ['books', 'كتب خارجية'],
    'general' => ['general', 'عام'],
]);

test('BR-T04 a section of another store is unknown on the field that named it', function (): void {
    $foreign = $this->sectionId('books', $this->otherTenant());

    expect($this->inTenant(fn (): array => sectionResolverError(fn () => $this->resolver->chosen($foreign, null))))
        ->toBe(['validation_failed', ['section_id' => ['العنصر المختار غير موجود']]]);
});

test('D109 an unknown kind is unknown on kind', function (): void {
    expect($this->inTenant(fn (): array => sectionResolverError(fn () => $this->resolver->chosen(null, 'furniture'))))
        ->toBe(['validation_failed', ['kind' => ['العنصر المختار غير موجود']]]);
});

test('D109 an archived section cannot be chosen, by id or by kind', function (): void {
    $archived = $this->inTenant(fn () => Section::factory()->archived()->create(['key' => 'old']));

    expect($this->inTenant(fn (): array => sectionResolverError(fn () => $this->resolver->chosen($archived->id, null))))
        ->toBe(['validation_failed', ['section_id' => ['العنصر المختار مؤرشف']]])
        ->and($this->inTenant(fn (): array => sectionResolverError(fn () => $this->resolver->chosen(null, 'old'))))
        ->toBe(['validation_failed', ['kind' => ['العنصر المختار مؤرشف']]]);
});

test('D109 c the fallback is the section flagged as fallback, whatever its key', function (): void {
    $this->inTenant(fn () => Section::query()->where('key', 'general')->update(['key' => 'misc', 'name' => 'متنوع']));

    expect($this->inTenant(fn () => $this->resolver->fallback())->name)->toBe('متنوع');
});

test('a store without a fallback section is a programming error', function (): void {
    app(TenantContext::class)->set($this->tenant);
    Section::query()->where('is_fallback', true)->update(['is_fallback' => false, 'key' => 'misc']);

    $this->resolver->fallback();
})->throws(RuntimeException::class, 'The store has no fallback section.');
