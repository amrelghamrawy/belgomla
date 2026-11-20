<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Models\Promotion;
use Carbon\CarbonImmutable;

/*
| /api/dashboard/promotions (BR-PR15, V2 Task 12, D90). `pricing.manage` (owner, manager); the permission matrix covers
| every role. Today is 2026-10-27 10:00 UTC; two الأضواء books and one other.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00'));
    $this->me = $this->actingAsOwner();
    [$this->first, $this->second, $this->outside] = $this->inTenant(fn (): array => [
        Product::factory()->withSku(['code' => 'ADW-1', 'base_price' => '100.00'])->create(['name' => 'الأضواء علوم'])->skus()->sole(),
        Product::factory()->withSku(['code' => 'ADW-2', 'base_price' => '50.00'])->create(['name' => 'الأضواء رياضيات'])->skus()->sole(),
        Product::factory()->withSku(['code' => 'MOA-1'])->create(['name' => 'المعلم عربي'])->skus()->sole(),
    ]);
    $this->body = fn (array $changes = []): array => [
        'name' => 'عرض الأضواء',
        'discount_percent' => 15,
        'starts_at' => '2026-11-01T00:00',
        'ends_at' => '2026-11-30T23:59',
        'filter' => ['q' => 'الأضواء'],
        ...$changes,
    ];
});

test('BR-PR15 an owner creates a promotion by filter: Cairo times stored utc, skus selected, audited', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/promotions', ($this->body)())
        ->assertCreated()
        ->assertJsonPath('data.name', 'عرض الأضواء')
        ->assertJsonPath('data.discount_percent', '15.00')
        ->assertJsonPath('data.starts_at', '2026-10-31T22:00:00+00:00')
        ->assertJsonPath('data.ends_at', '2026-11-30T21:59:00+00:00')
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.target', 'filter')
        ->assertJsonPath('data.skus_count', 2);

    expectDocumentedShape($response->json('data'), 'Promotion');
    $this->assertDatabaseHas('promotions', ['id' => $response->json('data.id'), 'tenant_id' => $this->tenant->id, 'created_by' => $this->me->id]);
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'promotion.created', 'entity_id' => $response->json('data.id'), 'actor_id' => $this->me->id]);
});

test('BR-PR15 a promotion by an explicit sku list comes back with its skus', function (): void {
    $this->tenantJson('POST', '/api/dashboard/promotions', ($this->body)(['filter' => null, 'sku_ids' => [$this->outside->id], 'status' => 'draft']))
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.target', 'skus')
        ->assertJsonPath('data.skus', [['sku_id' => $this->outside->id, 'code' => 'MOA-1', 'product_name' => 'المعلم عربي']]);
});

test('BR-PR15 promotion validation', function (array $changes, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/promotions', ($this->body)($changes))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [['name' => null], 'name'],
    'name too long' => [['name' => str_repeat('ع', 101)], 'name'],
    'missing percent' => [['discount_percent' => null], 'discount_percent'],
    'zero percent' => [['discount_percent' => 0], 'discount_percent'],
    '90 percent (D90 b)' => [['discount_percent' => 90], 'discount_percent'],
    'three decimals' => [['discount_percent' => '10.125'], 'discount_percent'],
    'missing start' => [['starts_at' => null], 'starts_at'],
    'not a date' => [['starts_at' => 'غدًا'], 'starts_at'],
    'end before start' => [['ends_at' => '2026-10-30T00:00'], 'ends_at'],
    'already over (D90 e)' => [['starts_at' => '2026-10-01T00:00', 'ends_at' => '2026-10-27T11:00'], 'ends_at'],
    'no target' => [['filter' => null], 'filter'],
    'both targets' => [['sku_ids' => [1]], 'filter'],
    'empty filter (BR-P12)' => [['filter' => ['q' => '']], 'filter'],
    'a filter that selects nothing' => [['filter' => ['q' => 'لا يوجد']], 'filter'],
    'an empty sku list' => [['filter' => null, 'sku_ids' => []], 'sku_ids'],
    'a repeated sku' => [['filter' => null, 'sku_ids' => [5, 5]], 'sku_ids.0'],
    'an unknown sku' => [['filter' => null, 'sku_ids' => [999999]], 'sku_ids'],
    'an unknown status' => [['status' => 'active'], 'status'],
]);

test('BR-T04 a sku of another store is refused like an unknown one', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku()->create()->skus()->sole(), $this->otherTenant());

    $this->tenantJson('POST', '/api/dashboard/promotions', ($this->body)(['filter' => null, 'sku_ids' => [$foreign->id]]))
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['sku_ids']]]);
});

test('BR-PR15 the list is latest start first, filtered by status, and never shows another store\'s', function (): void {
    [$draft, $live] = $this->inTenant(fn (): array => [
        Promotion::factory()->draft()->covering($this->first)->create(['starts_at' => '2026-11-05 00:00:00', 'ends_at' => '2026-11-06 00:00:00']),
        Promotion::factory()->covering($this->first, $this->second)->create(),
    ]);
    $this->inTenant(fn () => Promotion::factory()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/promotions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $draft->id)
        ->assertJsonPath('data.1.id', $live->id)
        ->assertJsonPath('data.1.status', 'active')
        ->assertJsonPath('data.1.skus_count', 2)
        ->assertJsonPath('meta.total', 2);
    $this->tenantJson('GET', '/api/dashboard/promotions?status=draft')->assertOk()->assertJsonCount(1, 'data');
    $this->tenantJson('GET', '/api/dashboard/promotions?status=paused')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['status']]]);
});

test('BR-PR15 one promotion', function (): void {
    $promotion = $this->inTenant(fn () => Promotion::factory()->covering($this->first)->create(['name' => 'عرض']));

    $response = $this->tenantJson('GET', "/api/dashboard/promotions/{$promotion->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'عرض')
        ->assertJsonPath('data.skus.0.sku_id', $this->first->id);

    expectDocumentedShape($response->json('data'), 'Promotion');
});

test('D90 f PATCH changes a promotion that has not started, selects again and audits', function (): void {
    $promotion = $this->inTenant(fn () => Promotion::factory()->upcoming()->covering($this->first)->create(['filter' => ['category' => null, 'f' => [], 'q' => 'الأضواء', 'status' => null]]));

    $this->tenantJson('PATCH', "/api/dashboard/promotions/{$promotion->id}", ['discount_percent' => '20'])
        ->assertOk()
        ->assertJsonPath('data.discount_percent', '20.00')
        ->assertJsonPath('data.skus_count', 2);

    $this->assertDatabaseHas('audit_logs', ['action' => 'promotion.updated', 'entity_id' => $promotion->id, 'actor_id' => $this->me->id]);
});

test('D90 f a running promotion cannot be edited', function (): void {
    $promotion = $this->inTenant(fn () => Promotion::factory()->covering($this->first)->create());

    $this->tenantJson('PATCH', "/api/dashboard/promotions/{$promotion->id}", ['name' => 'جديد'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'promotion_locked')
        ->assertJsonPath('error.details.status', 'active');
});

test('BR-PR15 PATCH validation', function (array $body, string $field): void {
    $promotion = $this->inTenant(fn () => Promotion::factory()->upcoming()->covering($this->first)->create());

    $this->tenantJson('PATCH', "/api/dashboard/promotions/{$promotion->id}", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'empty name' => [['name' => ''], 'name'],
    'percent above 90' => [['discount_percent' => 95], 'discount_percent'],
    'both targets' => [['filter' => ['q' => 'x'], 'sku_ids' => [1]], 'filter'],
    'end before the stored start (D90 e)' => [['ends_at' => '2026-10-27T13:00'], 'ends_at'],
]);

test('D90 f cancel stops a running promotion, audited; cancelling again is refused', function (): void {
    $promotion = $this->inTenant(fn () => Promotion::factory()->covering($this->first)->create());

    $this->tenantJson('POST', "/api/dashboard/promotions/{$promotion->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancelled_at', '2026-10-27T10:00:00+00:00');
    $this->tenantJson('POST', "/api/dashboard/promotions/{$promotion->id}/cancel")
        ->assertConflict()
        ->assertJsonPath('error.code', 'promotion_locked');

    $this->assertDatabaseHas('audit_logs', ['action' => 'promotion.cancelled', 'entity_id' => $promotion->id, 'actor_id' => $this->me->id]);
});

test('D90 d the preview counts the selection and prices a sample, writing nothing', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/promotions/preview', ['filter' => ['q' => 'الأضواء'], 'discount_percent' => 20])
        ->assertOk()
        ->assertJsonPath('data.skus_count', 2)
        ->assertJsonPath('data.sample.0', ['sku_id' => $this->first->id, 'code' => 'ADW-1', 'product_name' => 'الأضواء علوم', 'base_price' => '100.00', 'promotion_price' => '80.00']);

    expectDocumentedShape($response->json('data'), 'PromotionPreview');
    expect(Promotion::withoutTenantScope()->count())->toBe(0);
});

test('D90 d preview validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/promotions/preview', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'no target' => [[], 'filter'],
    'empty filter' => [['filter' => ['q' => ' ']], 'filter'],
    'percent out of range' => [['sku_ids' => [1], 'discount_percent' => 90], 'discount_percent'],
]);

test('BR-T04 another store\'s promotion is 404 everywhere and stays as it is', function (string $method, string $suffix): void {
    $foreign = $this->inTenant(fn () => Promotion::factory()->upcoming()->create(['name' => 'عرضهم']), $this->otherTenant());

    $this->tenantJson($method, "/api/dashboard/promotions/{$foreign->id}{$suffix}", ['name' => 'مسروق'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('promotions', ['id' => $foreign->id, 'name' => 'عرضهم', 'status' => 'scheduled']);
})->with([
    'show' => ['GET', ''],
    'update' => ['PATCH', ''],
    'cancel' => ['POST', '/cancel'],
]);

test('BR-T04 a dashboard session from one store cannot create promotions on another store\'s host', function (): void {
    $other = $this->otherTenant();
    $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);
    auth('tenant_user')->logout();
    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('POST', '/api/dashboard/promotions', ($this->body)(), $other)
        ->assertUnauthorized();

    expect(Promotion::withoutTenantScope()->count())->toBe(0);
});

test('D55 a retried create with the same Idempotency-Key makes one promotion', function (): void {
    $headers = ['Idempotency-Key' => 'promo-1'];

    $this->tenantJson('POST', '/api/dashboard/promotions', ($this->body)(), headers: $headers)->assertCreated();
    $this->tenantJson('POST', '/api/dashboard/promotions', ($this->body)(), headers: $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(Promotion::withoutTenantScope()->count())->toBe(1);
});

test('the promotion endpoints never leak an exception when debug is off', function (): void {
    config(['app.debug' => false]);
    Promotion::created(fn () => throw new RuntimeException('secret detail'));

    $this->tenantJson('POST', '/api/dashboard/promotions', ($this->body)())
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertDontSee('secret detail');
});
