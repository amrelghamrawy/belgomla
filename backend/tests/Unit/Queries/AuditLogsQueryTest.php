<?php

declare(strict_types=1);

use App\Modules\Audit\Data\AuditLogFiltersData;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Queries\AuditLogsQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);

    $this->log = fn (array $attributes = []): AuditLog => AuditLog::query()->create([
        'actor_type' => ActorType::TenantUser,
        'actor_id' => 1,
        'action' => 'order.status_changed',
        'entity_type' => 'orders',
        'entity_id' => 1,
        ...$attributes,
    ]);
    $this->ids = fn (AuditLogFiltersData $filters): array => app(AuditLogsQuery::class)->paginate($filters, 24)->pluck('id')->all();
});

test('BR-L the audit trail is newest first', function (): void {
    $old = ($this->log)(['created_at' => now()->subDay()]);
    $new = ($this->log)();

    expect(($this->ids)(new AuditLogFiltersData()))->toBe([$new->id, $old->id]);
});

test('BR-L the audit trail of one entity', function (): void {
    $match = ($this->log)(['entity_type' => 'collections', 'entity_id' => 5]);
    ($this->log)(['entity_type' => 'collections', 'entity_id' => 6]);
    ($this->log)(['entity_type' => 'orders', 'entity_id' => 5]);

    expect(($this->ids)(new AuditLogFiltersData('collections', 5)))->toBe([$match->id]);
});

test('BR-L the audit trail of one actor', function (): void {
    $match = ($this->log)(['actor_id' => 9]);
    ($this->log)(['actor_id' => 8]);

    expect(($this->ids)(new AuditLogFiltersData(actorId: 9)))->toBe([$match->id]);
});

test('BR-L the audit trail within a time range', function (): void {
    ($this->log)(['created_at' => CarbonImmutable::parse('2026-10-01 10:00:00', 'UTC')]);
    $match = ($this->log)(['created_at' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
    ($this->log)(['created_at' => CarbonImmutable::parse('2026-10-09 10:00:00', 'UTC')]);

    expect(($this->ids)(new AuditLogFiltersData(from: CarbonImmutable::parse('2026-10-04', 'UTC'), to: CarbonImmutable::parse('2026-10-06', 'UTC'))))->toBe([$match->id]);
});

test('BR-PR11 the audit rows of one bulk action share its batch id', function (): void {
    $batch = '9f1b2c3d-0000-4000-8000-000000000001';
    $rows = [($this->log)(['batch_id' => $batch])->id, ($this->log)(['batch_id' => $batch])->id];
    ($this->log)(['batch_id' => '9f1b2c3d-0000-4000-8000-000000000002']);
    ($this->log)();

    expect(($this->ids)(new AuditLogFiltersData(batchId: $batch)))->toBe(array_reverse($rows));
});

test('BR-T03 the audit trail never shows another tenant', function (): void {
    $this->inTenant(fn (): AuditLog => ($this->log)(), $this->otherTenant());

    expect(($this->ids)(new AuditLogFiltersData()))->toBe([]);
});
