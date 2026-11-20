<?php

declare(strict_types=1);

use App\Modules\Platform\Models\VerticalFieldTemplate;
use App\Modules\Platform\Models\VerticalFieldTemplateOption;

test('to array', function (): void {
    expect(array_keys(VerticalFieldTemplateOption::factory()->create()->fresh()->toArray()))->toBe([
        'id', 'template_id', 'label', 'sort_order', 'created_at', 'updated_at',
    ]);
});

test('casts', function (): void {
    expect(VerticalFieldTemplateOption::factory()->create(['sort_order' => '7'])->fresh()->sort_order)->toBe(7);
});

test('belongs to its template', function (): void {
    $template = VerticalFieldTemplate::factory()->select()->create();

    expect(VerticalFieldTemplateOption::factory()->for($template, 'template')->create()->template->is($template))->toBeTrue();
});

test('D110 an option label is unique within its template', function (): void {
    $template = VerticalFieldTemplate::factory()->select()->create();
    VerticalFieldTemplateOption::factory()->for($template, 'template')->create(['label' => 'الأضواء']);
    VerticalFieldTemplateOption::factory()->for($template, 'template')->create(['label' => 'الأضواء']);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);
