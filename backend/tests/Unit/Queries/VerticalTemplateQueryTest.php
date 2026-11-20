<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Platform\Data\FieldTemplateData;
use App\Modules\Platform\Data\VerticalData;
use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Platform\Models\VerticalFieldTemplate;
use App\Modules\Platform\Models\VerticalFieldTemplateOption;
use App\Modules\Platform\Models\VerticalSection;
use App\Modules\Platform\Queries\VerticalTemplateQuery;

test('D108 an active vertical is found by key', function (): void {
    $libraries = Vertical::query()->where('key', 'libraries')->sole();

    expect(app(VerticalTemplateQuery::class)->activeByKey('libraries'))
        ->toEqual(new VerticalData($libraries->id, 'libraries', 'مكتبات', VerticalStatus::Active));
});

test('D108 e an unknown or archived vertical is not offered', function (string $key): void {
    Vertical::factory()->archived()->create(['key' => 'clothes']);

    expect(app(VerticalTemplateQuery::class)->activeByKey($key))->toBeNull();
})->with([
    'unknown' => ['pharmacy'],
    'archived' => ['clothes'],
]);

test('D108 e the active keys leave archived verticals out, in display order', function (): void {
    Vertical::factory()->create(['key' => 'shoes', 'sort_order' => 3]);
    Vertical::factory()->create(['key' => 'clothes', 'sort_order' => 2]);
    Vertical::factory()->archived()->create(['key' => 'phones', 'sort_order' => 0]);

    expect(app(VerticalTemplateQuery::class)->activeKeys())->toBe(['libraries', 'clothes', 'shoes']);
});

test('D108 e a vertical is found by id even when archived, since its tenants keep it', function (): void {
    $archived = Vertical::factory()->archived()->create(['key' => 'clothes', 'name' => 'ملابس']);

    expect(app(VerticalTemplateQuery::class)->find($archived->id))
        ->toEqual(new VerticalData($archived->id, 'clothes', 'ملابس', VerticalStatus::Archived));
});

test('an unknown vertical id is a programming error', function (): void {
    app(VerticalTemplateQuery::class)->find(999_999);
})->throws(RuntimeException::class, 'Vertical #999999 does not exist.');

test('several verticals are read at once, keyed by id', function (): void {
    $clothes = Vertical::factory()->create(['key' => 'clothes']);
    $libraries = Vertical::query()->where('key', 'libraries')->sole();

    $found = app(VerticalTemplateQuery::class)->findMany([$libraries->id, $clothes->id]);

    expect(array_keys($found))->toEqualCanonicalizing([$libraries->id, $clothes->id])
        ->and($found[$clothes->id]->key)->toBe('clothes');
});

test('BR-P09 D110 the field templates come with their section key and options in order', function (): void {
    $vertical = Vertical::factory()->create();
    $section = VerticalSection::factory()->for($vertical)->create(['key' => 'shoes']);
    $template = VerticalFieldTemplate::factory()->for($vertical)->select()->create([
        'key' => 'size', 'label' => 'المقاس', 'vertical_section_id' => $section->id, 'is_required' => true, 'filter_order' => 2, 'sort_order' => 3,
    ]);
    VerticalFieldTemplateOption::factory()->for($template, 'template')->create(['label' => '42', 'sort_order' => 2]);
    VerticalFieldTemplateOption::factory()->for($template, 'template')->create(['label' => '41', 'sort_order' => 1]);

    expect(app(VerticalTemplateQuery::class)->fieldsFor($vertical->id))->toEqual([
        new FieldTemplateData($template->id, 'size', 'المقاس', FieldType::Select, 'shoes', true, true, 2, 3, ['41', '42']),
    ]);
});

test('D110 archived templates and templates of archived sections are left out', function (): void {
    $vertical = Vertical::factory()->create();
    $archivedSection = VerticalSection::factory()->for($vertical)->archived()->create();
    $kept = VerticalFieldTemplate::factory()->for($vertical)->create();
    VerticalFieldTemplate::factory()->for($vertical)->archived()->create();
    VerticalFieldTemplate::factory()->for($vertical)->create(['vertical_section_id' => $archivedSection->id]);

    expect(array_map(fn (FieldTemplateData $field): string => $field->key, app(VerticalTemplateQuery::class)->fieldsFor($vertical->id)))
        ->toBe([$kept->key]);
});

test('the templates of another vertical are not read', function (): void {
    VerticalFieldTemplate::factory()->create();

    expect(app(VerticalTemplateQuery::class)->fieldsFor(Vertical::factory()->create()->id))->toBe([]);
});
