<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $notification = MerchantNotification::factory()->create()->fresh();

    expect(array_keys($notification?->toArray() ?? []))->toBe(['id', 'tenant_id', 'tenant_user_id', 'type', 'data', 'read_at', 'created_at', 'updated_at']);
});

test('casts: type enum, data array, immutable read_at', function (): void {
    $notification = MerchantNotification::factory()->customerCancelled()->read()->create(['data' => ['order_number' => 7]])->fresh();

    expect($notification?->type)->toBe(NotificationType::CustomerCancelled)
        ->and($notification?->data)->toBe(['order_number' => 7])
        ->and($notification?->read_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('BR-N02 notifications live in our own notifications table and refuse unknown types', function (): void {
    expect(new MerchantNotification()->getTable())->toBe('notifications')
        ->and(fn () => MerchantNotification::factory()->create()->newQuery()->update(['type' => 'promo']))->toThrow(QueryException::class);
});

test('a notification can be addressed to one owner', function (): void {
    $owner = $this->makeOwner();

    expect(MerchantNotification::factory()->create(['tenant_user_id' => $owner->id])->fresh()?->tenant_user_id)->toBe($owner->id);
});
