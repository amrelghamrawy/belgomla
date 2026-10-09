<?php

declare(strict_types=1);

use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\TenantOrderCounter;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/*
| BelongsToTenant behaviour for every Orders model (rules/01-testing.md §4 Models, BR-T03, BR-CU02).
*/

dataset('orders models', [
    'order' => [Order::class],
    'order item' => [OrderItem::class],
    'status history' => [OrderStatusHistory::class],
    'order counter' => [TenantOrderCounter::class],
    'notification' => [MerchantNotification::class],
]);

test('a model created in tenant context gets that tenant id', function (string $model): void {
    /** @var class-string<Model> $model */
    $row = $this->inTenant(fn () => $model::factory()->create());

    expect($row->tenant_id)->toBe($this->tenant->id);
})->with('orders models');

test('BR-T03 creating a model without a tenant in context throws', function (string $model): void {
    $model::factory()->create();
})->with('orders models')->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 queries without a tenant in context return nothing', function (string $model): void {
    $this->inTenant(fn () => $model::factory()->create());
    app(TenantContext::class)->clear();

    expect($model::query()->count())->toBe(0);
})->with('orders models');

test('BR-CU02 queries in context never see another tenant', function (string $model): void {
    $this->inTenant(fn () => $model::factory()->create(), $this->otherTenant());

    expect($this->inTenant(fn () => $model::query()->count()))->toBe(0);
})->with('orders models');
