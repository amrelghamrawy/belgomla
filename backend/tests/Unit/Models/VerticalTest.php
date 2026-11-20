<?php

declare(strict_types=1);

use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Platform\Models\VerticalFieldTemplate;
use App\Modules\Platform\Models\VerticalSection;
use Carbon\CarbonImmutable;

test('to array', function (): void {
    expect(array_keys(Vertical::factory()->create()->fresh()->toArray()))->toBe([
        'id', 'key', 'name', 'status', 'sort_order', 'created_at', 'updated_at',
    ]);
});

test('casts', function (): void {
    $vertical = Vertical::factory()->archived()->create(['sort_order' => '3'])->fresh();

    expect($vertical->status)->toBe(VerticalStatus::Archived)
        ->and($vertical->sort_order)->toBe(3)
        ->and($vertical->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('D108 a the key is unique across the platform', function (): void {
    Vertical::factory()->create(['key' => 'libraries']);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

test('has sections', function (): void {
    $section = VerticalSection::factory()->create();

    expect($section->vertical->sections->modelKeys())->toBe([$section->id]);
});

test('has field templates', function (): void {
    $template = VerticalFieldTemplate::factory()->create();

    expect($template->vertical->fieldTemplates->modelKeys())->toBe([$template->id]);
});
