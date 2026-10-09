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

test('working hours are normalised to typed rows', function (): void {
    $data = TenantSettingsData::fromRequest(settingsRequest([
        'working_hours' => [['day' => '6', 'from' => '09:00', 'to' => '21:00']],
    ]));

    expect($data->workingHours)->toBe([['day' => 6, 'from' => '09:00', 'to' => '21:00']]);
});
