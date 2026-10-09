<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Actions\MarkNotificationsRead;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    [$this->first, $this->second, $this->third] = MerchantNotification::factory()->count(3)->create()->all();
});

test('BR-N02 the given notifications are marked read and counted', function (): void {
    $this->freezeSecond();

    $marked = app(MarkNotificationsRead::class)->handle([$this->first->id, $this->third->id]);

    expect($marked)->toBe(2)
        ->and($this->first->refresh()->read_at?->equalTo(now()))->toBeTrue()
        ->and($this->second->refresh()->read_at)->toBeNull()
        ->and($this->third->refresh()->read_at)->not->toBeNull();
});

test('BR-N02 all unread notifications are marked when no ids are given', function (): void {
    expect(app(MarkNotificationsRead::class)->handle(null))->toBe(3)
        ->and(MerchantNotification::query()->whereNull('read_at')->count())->toBe(0);
});

test('already read notifications keep their time and are not counted', function (): void {
    $this->travelTo(now()->subDay());
    app(MarkNotificationsRead::class)->handle([$this->first->id]);
    $readAt = $this->first->refresh()->read_at;
    $this->travelBack();

    expect(app(MarkNotificationsRead::class)->handle([$this->first->id, $this->second->id]))->toBe(1)
        ->and($this->first->refresh()->read_at?->equalTo($readAt))->toBeTrue();
});

test('BR-T03 ids of another tenant are skipped', function (): void {
    $theirs = $this->inTenant(fn () => MerchantNotification::factory()->create(), $this->otherTenant());

    expect(app(MarkNotificationsRead::class)->handle([$theirs->id]))->toBe(0)
        ->and(MerchantNotification::withoutTenantScope()->findOrFail($theirs->id)->read_at)->toBeNull();
});

test('BR-L marking is audited with the ids, nothing to mark writes nothing', function (): void {
    app(MarkNotificationsRead::class)->handle([$this->second->id]);
    app(MarkNotificationsRead::class)->handle([$this->second->id]);

    expect(AuditLog::query()->sole()->only(['action', 'entity_type', 'new_values']))->toBe([
        'action' => 'notifications.read', 'entity_type' => 'notifications', 'new_values' => ['ids' => [$this->second->id]],
    ]);
});
