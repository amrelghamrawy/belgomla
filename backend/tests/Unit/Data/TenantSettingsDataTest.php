<?php

declare(strict_types=1);

use App\Modules\Tenancy\Data\TenantSettingsData;
use App\Modules\Tenancy\Http\Requests\UpdateSettingsRequest;

function settingsRequest(array $payload): UpdateSettingsRequest
{
    $request = UpdateSettingsRequest::create('/api/dashboard/settings', 'PUT', $payload);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

test('BR-C05 BR-C06 the default credit terms are an integer and a 2-decimal string, null when cleared', function (): void {
    expect(TenantSettingsData::fromRequest(settingsRequest(['default_payment_terms_days' => '45', 'default_credit_limit' => '1500.5']))->settingColumns())
        ->toBe(['default_payment_terms_days' => 45, 'default_credit_limit' => '1500.50'])
        ->and(TenantSettingsData::fromRequest(settingsRequest(['default_payment_terms_days' => null, 'default_credit_limit' => null]))->settingColumns())
        ->toBe(['default_payment_terms_days' => null, 'default_credit_limit' => null]);
});

test('BR-N03 the notified statuses keep the platform order and the default text is not stored', function (): void {
    expect(TenantSettingsData::fromRequest(settingsRequest([
        'order_notify_statuses' => ['cancelled', 'confirmed'],
        'order_status_messages' => ['confirmed' => '  نص  ', 'delivered' => App\Support\OrderStatusMessage::DEFAULTS['delivered'], 'cancelled' => ''],
    ]))->settingColumns())->toBe([
        'order_notify_statuses' => ['confirmed', 'cancelled'],
        'order_status_messages' => ['confirmed' => 'نص'],
    ]);
});

test('BR-N03 messages that are all defaults are stored as null', function (): void {
    expect(TenantSettingsData::fromRequest(settingsRequest(['order_status_messages' => ['confirmed' => null]]))->settingColumns())
        ->toBe(['order_status_messages' => null]);
});

test('only sent fields are carried, nullable fields can be cleared, money is a 2-decimal string', function (): void {
    $data = TenantSettingsData::fromRequest(settingsRequest([
        'name' => 'x', 'phone' => null, 'mov_enabled' => true, 'mov_amount' => 1000, 'primary_color' => '#aabbcc',
    ]));

    expect($data->name)->toBe('x')
        ->and($data->sent)->toBe(['name', 'primary_color', 'phone', 'mov_enabled', 'mov_amount'])
        ->and($data->settingColumns())->toBe([
            'primary_color' => '#AABBCC',
            'phone' => null,
            'mov_enabled' => true,
            'mov_amount' => '1000.00',
        ]);
});

test('BR-CU08 an empty invitation message is carried as null, the default', function (): void {
    $data = TenantSettingsData::fromRequest(settingsRequest(['invitation_message' => '']));

    expect($data->settingColumns())->toBe(['invitation_message' => null]);
});

test('working hours are normalised to typed rows', function (): void {
    $data = TenantSettingsData::fromRequest(settingsRequest([
        'working_hours' => [['day' => '6', 'from' => '09:00', 'to' => '21:00']],
    ]));

    expect($data->workingHours)->toBe([['day' => 6, 'from' => '09:00', 'to' => '21:00']]);
});
