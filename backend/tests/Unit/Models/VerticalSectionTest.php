<?php

declare(strict_types=1);

use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Platform\Models\VerticalSection;

test('to array', function (): void {
    expect(array_keys(VerticalSection::factory()->create()->fresh()->toArray()))->toBe([
        'id', 'vertical_id', 'key', 'name', 'reference_price_label', 'icon', 'status', 'sort_order', 'created_at', 'updated_at',
    ]);
});

test('casts', function (): void {
    $section = VerticalSection::factory()->archived()->create(['sort_order' => '2'])->fresh();

    expect($section->status)->toBe(VerticalStatus::Archived)
        ->and($section->sort_order)->toBe(2);
});

test('BR-PR10 D109 d the reference price label defaults to سعر القطاعي', function (): void {
    $section = VerticalSection::query()->create(['vertical_id' => Vertical::factory()->create()->id, 'key' => 'toys', 'name' => 'ألعاب'])->fresh();

    expect($section->reference_price_label)->toBe('سعر القطاعي');
});

test('belongs to its vertical', function (): void {
    $vertical = Vertical::factory()->create();

    expect(VerticalSection::factory()->for($vertical)->create()->vertical->is($vertical))->toBeTrue();
});

test('D109 a the key is unique within its vertical', function (): void {
    $vertical = Vertical::factory()->create();
    VerticalSection::factory()->for($vertical)->create(['key' => 'books']);
    VerticalSection::factory()->for($vertical)->create(['key' => 'books']);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

test('two verticals may use the same section key', function (): void {
    VerticalSection::factory()->create(['key' => 'books']);
    VerticalSection::factory()->create(['key' => 'books']);

    expect(VerticalSection::query()->where('key', 'books')->count())->toBe(3);
});
