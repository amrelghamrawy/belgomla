<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Platform\Models\VerticalFieldTemplate;
use App\Modules\Platform\Models\VerticalFieldTemplateOption;
use App\Modules\Platform\Models\VerticalSection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('to array', function (): void {
    expect(array_keys(VerticalFieldTemplate::factory()->create()->fresh()->toArray()))->toBe([
        'id', 'vertical_id', 'vertical_section_id', 'key', 'label', 'type', 'is_required', 'is_filterable',
        'filter_order', 'sort_order', 'status', 'created_at', 'updated_at',
    ]);
});

test('casts', function (): void {
    $template = VerticalFieldTemplate::factory()->select()->archived()->create(['is_required' => 1, 'filter_order' => '4', 'sort_order' => '5'])->fresh();

    expect($template->type)->toBe(FieldType::Select)
        ->and($template->status)->toBe(VerticalStatus::Archived)
        ->and($template->is_required)->toBeTrue()
        ->and($template->is_filterable)->toBeTrue()
        ->and($template->filter_order)->toBe(4)
        ->and($template->sort_order)->toBe(5);
});

test('D110 a a template may belong to one section of its vertical', function (): void {
    $section = VerticalSection::factory()->create();
    $template = VerticalFieldTemplate::factory()->create(['vertical_id' => $section->vertical_id, 'vertical_section_id' => $section->id]);

    expect($template->section?->is($section))->toBeTrue()
        ->and($template->vertical->is($section->vertical))->toBeTrue();
});

test('D110 a a template without a section applies to every section', function (): void {
    expect(VerticalFieldTemplate::factory()->create()->section)->toBeNull();
});

test('has options', function (): void {
    $option = VerticalFieldTemplateOption::factory()->create();

    expect($option->template->options->modelKeys())->toBe([$option->id]);
});

test('BR-P09 the key is unique within its vertical', function (): void {
    $vertical = Vertical::factory()->create();
    VerticalFieldTemplate::factory()->for($vertical)->create(['key' => 'grade']);
    VerticalFieldTemplate::factory()->for($vertical)->create(['key' => 'grade']);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

test('the type is one of the field types', function (): void {
    $template = VerticalFieldTemplate::factory()->create();

    DB::table('vertical_field_templates')->where('id', $template->id)->update(['type' => 'date']);
})->throws(QueryException::class);
