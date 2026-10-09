<?php

declare(strict_types=1);

use App\Modules\Orders\Actions\ApplyCollectedAmount;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->order = Order::factory()->create(['items_total' => '500.00', 'order_total' => '500.00']);
});

test('BR-C03 the collected amount is stored with the status derived from it', function (string $collected, CollectionStatus $status): void {
    app(ApplyCollectedAmount::class)->handle($this->order, $collected);

    expect($this->order->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => $collected, 'collection_status' => $status]);
})->with([
    'unpaid' => ['0.00', CollectionStatus::Unpaid],
    'partially collected' => ['120.50', CollectionStatus::PartiallyCollected],
    'collected' => ['500.00', CollectionStatus::Collected],
]);

test('BR-C03 a voided collection brings a collected order back to unpaid', function (): void {
    app(ApplyCollectedAmount::class)->handle($this->order, '500.00');

    app(ApplyCollectedAmount::class)->handle($this->order, '0.00');

    expect($this->order->refresh()->collection_status)->toBe(CollectionStatus::Unpaid);
});

test('BR-C03 more than the order total never reaches the order', function (string $collected): void {
    app(ApplyCollectedAmount::class)->handle($this->order, $collected);
})->with(['above the total' => ['500.01'], 'negative' => ['-1.00']])->throws(RuntimeException::class);
