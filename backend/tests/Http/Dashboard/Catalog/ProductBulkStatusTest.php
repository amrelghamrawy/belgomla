<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();

    [$this->oldYear, $this->newYear] = $this->inTenant(function (): array {
        $year = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'academic_year']);

        return [
            ProductFieldOption::factory()->for($year, 'definition')->create(['label' => '2025/2026']),
            ProductFieldOption::factory()->for($year, 'definition')->create(['label' => '2026/2027']),
        ];
    });
});

test('BR-P12 inactivating last year by filter changes only matching products, with one batch id', function (): void {
    [$old1, $old2, $current] = $this->inTenant(fn (): array => [
        Product::factory()->withSku()->create(['custom_fields' => ['academic_year' => $this->oldYear->id]]),
        Product::factory()->withSku()->create(['custom_fields' => ['academic_year' => $this->oldYear->id]]),
        Product::factory()->withSku()->create(['custom_fields' => ['academic_year' => $this->newYear->id]]),
    ]);

    $response = $this->tenantJson('POST', '/api/dashboard/products/bulk/status', [
        'filter' => ['f' => ['academic_year' => $this->oldYear->id]],
        'status' => 'inactive',
    ])
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['affected', 'skipped', 'batch_id']])
        ->assertJsonPath('data.affected', 2)
        ->assertJsonPath('data.skipped', 0);

    $batch = $response->json('data.batch_id');
    $logs = $this->inTenant(fn () => AuditLog::query()->where('batch_id', $batch)->get());

    $this->assertDatabaseHas('products', ['id' => $old1->id, 'status' => 'inactive']);
    $this->assertDatabaseHas('products', ['id' => $old2->id, 'status' => 'inactive']);
    $this->assertDatabaseHas('products', ['id' => $current->id, 'status' => 'active']);
    expect($logs->pluck('action')->sort()->values()->all())->toBe(['product.bulk_status_changed', 'product.status_changed', 'product.status_changed'])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->me->id]);
});

test('D29 products whose transition is not allowed are skipped and counted', function (): void {
    $this->inTenant(fn (): array => [
        Product::factory()->withSku()->inactive()->create(['custom_fields' => ['academic_year' => $this->oldYear->id]]),
        Product::factory()->withSku()->create(['custom_fields' => ['academic_year' => $this->oldYear->id]]),
    ]);

    $this->tenantJson('POST', '/api/dashboard/products/bulk/status', [
        'filter' => ['f' => ['academic_year' => $this->oldYear->id]],
        'status' => 'inactive',
    ])
        ->assertOk()
        ->assertJsonPath('data.affected', 1)
        ->assertJsonPath('data.skipped', 1);
});

test('bulk status combines the filters of the list', function (): void {
    [$match] = $this->inTenant(fn (): array => [
        Product::factory()->withSku()->comingSoon()->create(['name' => 'علوم', 'custom_fields' => ['academic_year' => $this->newYear->id]]),
        Product::factory()->withSku()->comingSoon()->create(['name' => 'رياضيات', 'custom_fields' => ['academic_year' => $this->newYear->id]]),
    ]);

    $this->tenantJson('POST', '/api/dashboard/products/bulk/status', [
        'filter' => ['status' => 'coming_soon', 'q' => 'علوم', 'f' => ['academic_year' => $this->newYear->id]],
        'status' => 'active',
    ])->assertOk()->assertJsonPath('data.affected', 1);

    $this->assertDatabaseHas('products', ['id' => $match->id, 'status' => 'active']);
});

test('an empty filter is refused so a mistake cannot change the whole catalog', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->withSku()->create());

    $this->tenantJson('POST', '/api/dashboard/products/bulk/status', ['filter' => ['q' => ''], 'status' => 'inactive'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['filter']]]);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'active']);
});

test('bulk status validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/products/bulk/status', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing filter' => [['status' => 'inactive'], 'filter'],
    'missing status' => [['filter' => ['q' => 'x']], 'status'],
    'unknown status' => [['filter' => ['q' => 'x'], 'status' => 'gone'], 'status'],
    'filter status unknown' => [['filter' => ['status' => 'gone'], 'status' => 'inactive'], 'filter.status'],
    'filter category not an id' => [['filter' => ['category' => 'books'], 'status' => 'inactive'], 'filter.category'],
    'filter fields not a map' => [['filter' => ['f' => 'x'], 'status' => 'inactive'], 'filter.f'],
]);

test('BR-T04 bulk status never touches another tenant', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku()->create(['name' => 'علوم']), $this->otherTenant());

    $this->tenantJson('POST', '/api/dashboard/products/bulk/status', ['filter' => ['q' => 'علوم'], 'status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.affected', 0);

    $this->assertDatabaseHas('products', ['id' => $foreign->id, 'status' => 'active']);
});
