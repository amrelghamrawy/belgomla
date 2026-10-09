<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Actions\UpdateDeliveryAddress;
use App\Modules\Orders\Data\DeliveryAddressData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\AddressLocked;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->order = Order::factory()->create(['delivery_name' => 'مكتبة الأمل', 'delivery_phone' => '+201012345678']);
    $this->data = new DeliveryAddressData(governorate: 'الجيزة', city: 'الدقي', addressLine: '5 شارع التحرير', label: 'المخزن', landmark: 'أمام البنك');
});

test('BR-O10 D9 the merchant replaces the delivery address snapshot, keeping name and phone when not given', function (): void {
    $order = app(UpdateDeliveryAddress::class)->handle($this->order, $this->data);

    expect($order->refresh()->delivery_address)->toEqual(['label' => 'المخزن', 'governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => '5 شارع التحرير', 'landmark' => 'أمام البنك'])
        ->and($order->delivery_name)->toBe('مكتبة الأمل')
        ->and($order->delivery_phone)->toBe('+201012345678');
});

test('BR-O10 the recipient name and phone can be corrected too', function (): void {
    $data = new DeliveryAddressData(governorate: 'الجيزة', city: 'الدقي', addressLine: '5 شارع التحرير', name: 'أحمد', phone: '+201112223334');

    $order = app(UpdateDeliveryAddress::class)->handle($this->order, $data);

    expect($order->refresh()->only(['delivery_name', 'delivery_phone']))->toBe(['delivery_name' => 'أحمد', 'delivery_phone' => '+201112223334']);
});

test('BR-L the change is audited with the old and new snapshot', function (): void {
    $old = $this->order->delivery_address;

    app(UpdateDeliveryAddress::class)->handle($this->order, $this->data);

    expect(AuditLog::query()->sole())
        ->action->toBe('order.delivery_address_changed')
        ->entity_id->toBe($this->order->id)
        ->old_values->toEqual(['delivery_address' => $old])
        ->new_values->toEqual(['delivery_address' => $this->data->address()]);
});

test('BR-CU03 the customer\'s saved addresses are untouched', function (): void {
    $saved = CustomerAddress::factory()->for($this->order->customer)->create(['city' => 'مدينة نصر']);

    app(UpdateDeliveryAddress::class)->handle($this->order, $this->data);

    expect($saved->refresh()->city)->toBe('مدينة نصر');
});

test('D9 the address is locked from out for delivery on', function (OrderStatus $status): void {
    $this->order->forceFill(['status' => $status])->save();

    expect(fn () => app(UpdateDeliveryAddress::class)->handle($this->order, $this->data))->toThrow(function (AddressLocked $e) use ($status): void {
        expect($e->status)->toBe(409)->and($e->errorCode)->toBe('address_locked')->and($e->details)->toBe(['status' => $status->value]);
    });

    expect(AuditLog::query()->count())->toBe(0)
        ->and($this->order->refresh()->delivery_address['city'])->toBe('مدينة نصر');
})->with(['out for delivery' => [OrderStatus::OutForDelivery], 'delivered' => [OrderStatus::Delivered], 'cancelled' => [OrderStatus::Cancelled]]);

test('D9 the address is editable while pending, confirmed or preparing', function (OrderStatus $status): void {
    $this->order->forceFill(['status' => $status])->save();

    expect(app(UpdateDeliveryAddress::class)->handle($this->order, $this->data)->refresh()->delivery_address['city'])->toBe('الدقي');
})->with(['pending' => [OrderStatus::Pending], 'confirmed' => [OrderStatus::Confirmed], 'preparing' => [OrderStatus::Preparing]]);
