<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ApplyVerticalTemplate;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Platform\Models\VerticalFieldTemplate;
use App\Modules\Platform\Models\VerticalSection;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

function librariesId(): int
{
    return Vertical::query()->where('key', 'libraries')->sole()->id;
}

test('BR-P09 the libraries vertical copies its 11 filterable select fields, each in the section of its template', function (): void {
    $created = app(ApplyVerticalTemplate::class)->handle(librariesId());

    $kinds = ProductFieldDefinition::query()->orderBy('id')->get()
        ->mapWithKeys(fn (ProductFieldDefinition $field): array => [$field->key => $field->section?->key])
        ->all();

    expect($created)->toBe(['grade', 'subject', 'publisher', 'term', 'track', 'edition_type', 'academic_year', 'brand', 'color', 'size', 'material'])
        ->and($kinds)->toBe([
            'grade' => 'books', 'subject' => 'books', 'publisher' => 'books', 'term' => 'books', 'track' => 'books',
            'edition_type' => 'books', 'academic_year' => 'books',
            'brand' => 'stationery', 'color' => 'stationery', 'size' => 'stationery',
            'material' => null,
        ])
        ->and(ProductFieldDefinition::query()->get()->every(fn (ProductFieldDefinition $f): bool => $f->type === FieldType::Select && $f->is_filterable && ! $f->is_required))->toBeTrue();
});

test('BR-P09 the facet order of the school-book fields is kept: grade, subject, publisher first (D18)', function (): void {
    app(ApplyVerticalTemplate::class)->handle(librariesId());

    expect(ProductFieldDefinition::query()->where('section_id', Database\Factories\CategoryFactory::sectionId('books'))->orderBy('filter_order')->pluck('key')->all())
        ->toBe(['grade', 'subject', 'publisher', 'term', 'track', 'edition_type', 'academic_year']);
});

test('BR-P09 options are copied with their labels in order', function (): void {
    app(ApplyVerticalTemplate::class)->handle(librariesId());

    $term = ProductFieldDefinition::query()->where('key', 'term')->sole();

    expect($term->options()->orderBy('sort_order')->get()->map(fn (ProductFieldOption $o): array => [$o->label, $o->sort_order])->all())
        ->toBe([['الترم الأول', 1], ['الترم الثاني', 2], ['كامل العام', 3]])
        ->and(ProductFieldDefinition::query()->where('key', 'grade')->sole()->options()->count())->toBe(14)
        ->and(ProductFieldDefinition::query()->where('key', 'brand')->sole()->options()->count())->toBe(0);
});

test('BR-P09 applying the templates twice creates nothing new', function (): void {
    app(ApplyVerticalTemplate::class)->handle(librariesId());
    $options = ProductFieldOption::query()->count();

    $second = app(ApplyVerticalTemplate::class)->handle(librariesId());

    expect($second)->toBe([])
        ->and(ProductFieldDefinition::query()->count())->toBe(11)
        ->and(ProductFieldOption::query()->count())->toBe($options)
        ->and(AuditLog::query()->where('action', 'catalog.template_applied')->count())->toBe(1);
});

test('BR-P09 the audit record names the vertical and the fields created', function (): void {
    app(ApplyVerticalTemplate::class)->handle(librariesId());

    expect(AuditLog::query()->where('action', 'catalog.template_applied')->sole()->new_values)
        ->toEqual(['vertical' => 'libraries', 'fields' => ['grade', 'subject', 'publisher', 'term', 'track', 'edition_type', 'academic_year', 'brand', 'color', 'size', 'material']]);
});

test('D110 b the templates fill missing options of an existing field without touching the merchant edits', function (): void {
    $term = ProductFieldDefinition::factory()->select()->create(['key' => 'term', 'label' => 'الفصل الدراسي']);
    ProductFieldOption::factory()->for($term, 'definition')->create(['label' => 'الترم الأول']);

    app(ApplyVerticalTemplate::class)->handle(librariesId());

    expect($term->refresh()->label)->toBe('الفصل الدراسي')
        ->and($term->options()->pluck('label')->all())->toBe(['الترم الأول', 'الترم الثاني', 'كامل العام']);
});

test('D110 an archived template, and a template of an archived section, are not copied', function (): void {
    $vertical = Vertical::factory()->create();
    $archivedSection = VerticalSection::factory()->for($vertical)->archived()->create(['key' => 'books']);
    VerticalFieldTemplate::factory()->for($vertical)->create(['key' => 'kept']);
    VerticalFieldTemplate::factory()->for($vertical)->archived()->create(['key' => 'retired']);
    VerticalFieldTemplate::factory()->for($vertical)->create(['key' => 'hidden', 'vertical_section_id' => $archivedSection->id]);

    expect(app(ApplyVerticalTemplate::class)->handle($vertical->id))->toBe(['kept']);
});

test('D109 D110 a template of a new vertical section lands in the store section made from it, linked to the template', function (): void {
    $vertical = Vertical::factory()->create();
    $shoes = VerticalSection::factory()->for($vertical)->create(['key' => 'shoes', 'name' => 'أحذية']);
    $template = VerticalFieldTemplate::factory()->for($vertical)->create(['key' => 'shoe_size', 'vertical_section_id' => $shoes->id]);

    app(ApplyVerticalTemplate::class)->handle($vertical->id);

    $field = ProductFieldDefinition::query()->where('key', 'shoe_size')->sole();

    expect($field->section?->name)->toBe('أحذية')
        ->and($field->section?->vertical_section_id)->toBe($shoes->id)
        ->and($field->template_id)->toBe($template->id);
});

test('D110 b a field the store already has is linked to the template of the same key', function (): void {
    $term = ProductFieldDefinition::factory()->select()->create(['key' => 'term']);

    app(ApplyVerticalTemplate::class)->handle(librariesId());

    expect($term->refresh()->template_id)->toBe(VerticalFieldTemplate::query()->where('vertical_id', librariesId())->where('key', 'term')->sole()->id);
});

test('a vertical without templates is a no-op', function (): void {
    expect(app(ApplyVerticalTemplate::class)->handle(Vertical::factory()->create()->id))->toBe([])
        ->and(ProductFieldDefinition::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
