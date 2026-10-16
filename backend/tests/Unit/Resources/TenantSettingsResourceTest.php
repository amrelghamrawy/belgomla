<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Resources\TenantSettingsResource;
use App\Modules\Tenancy\Http\Resources\TenantUserResource;
use App\Support\InvitationMessage;
use Illuminate\Http\Request;

test('the settings resource has the documented key set and hides operational fields on the store', function (): void {
    $settings = $this->tenant->settings;

    $store = new TenantSettingsResource($settings)->resolve(Request::create('http://alnoor.gomal.test/api/store/tenant'));
    $dashboard = new TenantSettingsResource($settings)->resolve(Request::create('http://alnoor.gomal.test/api/dashboard/settings'));

    expect(array_keys($store))->toBe([
        'name', 'subdomain', 'logo_url', 'favicon_url', 'colors', 'phone', 'whatsapp', 'address', 'description',
        'working_hours', 'currency', 'require_login_for_prices', 'require_customer_approval', 'mov',
    ])
        ->and($store)->not->toHaveKeys(['logo_path', 'favicon_path', 'tenant_id'])
        ->and($dashboard)->toHaveKey('stale_pending_hours', 24)
        ->and($dashboard)->toHaveKey('invitation_message', null)
        ->and($dashboard)->toHaveKey('invitation_message_default', InvitationMessage::DEFAULT)
        ->and($store['mov'])->toBe(['enabled' => false, 'amount' => null]);
});

test('the user resource never exposes the password or token', function (): void {
    $user = $this->makeOwner(attributes: ['last_login_at' => '2026-10-09 10:00:00']);

    $data = new TenantUserResource($user)->resolve(Request::create('/'));

    expect(array_keys($data))->toBe(['id', 'name', 'email', 'role', 'status', 'last_login_at'])
        ->and($data['role'])->toBe('owner')
        ->and($data['last_login_at'])->toBe('2026-10-09T10:00:00+00:00');
});
