<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Support\InvitationMessage;
use App\Support\OrderStatusMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
});

test('settings are returned with the storefront shape plus merchant-only fields', function (): void {
    $this->tenantJson('GET', '/api/dashboard/settings')
        ->assertOk()
        ->assertJsonPath('data.mov.enabled', false)
        ->assertJsonPath('data.stale_pending_hours', 24);
});

test('an owner updates settings and the change is audited with old and new values', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', [
        'name' => 'مكتبة النور الجديدة',
        'phone' => '+201000000000',
        'mov_enabled' => true,
        'mov_amount' => 1000,
        'require_login_for_prices' => true,
        'working_hours' => [['day' => 6, 'from' => '09:00', 'to' => '21:00']],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'مكتبة النور الجديدة')
        ->assertJsonPath('data.mov.enabled', true)
        ->assertJsonPath('data.mov.amount', '1000.00')
        ->assertJsonPath('data.require_login_for_prices', true)
        ->assertJsonPath('data.working_hours.0.day', 6);

    $this->assertDatabaseHas('tenants', ['id' => $this->tenant->id, 'name' => 'مكتبة النور الجديدة']);

    $logs = $this->asTenant($this->tenant, fn () => AuditLog::query()->orderBy('id')->get());

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->action)->toBe('tenant.renamed')
        ->and($logs[0]->old_values)->toBe(['name' => 'مكتبة النور'])
        ->and($logs[1]->action)->toBe('settings.updated')
        ->and($logs[1]->actor_type->value)->toBe('tenant_user')
        ->and($logs[1]->actor_id)->toBe($this->owner->id)
        ->and($logs[1]->old_values)->toMatchArray(['mov_enabled' => false, 'mov_amount' => null, 'phone' => null])
        ->and($logs[1]->new_values)->toMatchArray(['mov_enabled' => true, 'phone' => '+201000000000']);
});

test('an update that changes nothing writes no audit record', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', ['mov_enabled' => false, 'name' => 'مكتبة النور'])->assertOk();

    expect($this->asTenant($this->tenant, fn (): int => AuditLog::query()->count()))->toBe(0);
});

test('a nullable field sent as null is cleared', function (): void {
    $this->tenant->settings->update(['phone' => '+201000000000']);

    $this->tenantJson('PUT', '/api/dashboard/settings', ['phone' => null])->assertOk()->assertJsonPath('data.phone', null);
});

test('settings validation', function (array $body, string $field): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'mov amount required when enabled' => [['mov_enabled' => true, 'mov_amount' => null], 'mov_amount'],
    'negative mov amount' => [['mov_enabled' => true, 'mov_amount' => -5], 'mov_amount'],
    'bad primary color' => [['primary_color' => 'red'], 'primary_color'],
    'bad secondary color' => [['secondary_color' => '#12345'], 'secondary_color'],
    'empty name' => [['name' => ''], 'name'],
    'working hours day out of range' => [['working_hours' => [['day' => 7, 'from' => '09:00', 'to' => '10:00']]], 'working_hours.0.day'],
    'working hours bad time' => [['working_hours' => [['day' => 1, 'from' => '9am', 'to' => '10:00']]], 'working_hours.0.from'],
    'stale hours too large' => [['stale_pending_hours' => 10000], 'stale_pending_hours'],
    'invitation text too long' => [['invitation_message' => str_repeat('ا', 501)], 'invitation_message'],
    'invitation text unknown placeholder' => [['invitation_message' => 'أهلًا {name}'], 'invitation_message'],
    'invitation text not a string' => [['invitation_message' => ['x']], 'invitation_message'],
    'default terms negative' => [['default_payment_terms_days' => -1], 'default_payment_terms_days'],
    'default terms over a year' => [['default_payment_terms_days' => 366], 'default_payment_terms_days'],
    'default limit negative' => [['default_credit_limit' => '-1'], 'default_credit_limit'],
    'default limit three decimals' => [['default_credit_limit' => '1.001'], 'default_credit_limit'],
    'unknown notify status' => [['order_notify_statuses' => ['pending']], 'order_notify_statuses.0'],
    'notify statuses not a list' => [['order_notify_statuses' => 'confirmed'], 'order_notify_statuses'],
    'duplicate notify status' => [['order_notify_statuses' => ['confirmed', 'confirmed']], 'order_notify_statuses.0'],
    'message for an unknown status' => [['order_status_messages' => ['pending' => 'x']], 'order_status_messages'],
    'message too long' => [['order_status_messages' => ['confirmed' => str_repeat('ا', 501)]], 'order_status_messages.confirmed'],
    'message unknown placeholder' => [['order_status_messages' => ['confirmed' => 'أهلًا {name}']], 'order_status_messages.confirmed'],
    'reason outside cancelled' => [['order_status_messages' => ['delivered' => '{سبب_الإلغاء}']], 'order_status_messages.delivered'],
    'expiry zero' => [['pending_expiry_hours' => 0], 'pending_expiry_hours'],
    'expiry over a month' => [['pending_expiry_hours' => 721], 'pending_expiry_hours'],
    'expiry not a number' => [['pending_expiry_hours' => 'soon'], 'pending_expiry_hours'],
    'expiry below the stale hours sent with it' => [['stale_pending_hours' => 48, 'pending_expiry_hours' => 24], 'pending_expiry_hours'],
    'expiry below the stored stale hours' => [['pending_expiry_hours' => 23], 'pending_expiry_hours'],
]);

test('BR-INV07 D81 an owner turns the automatic expiry on and off; it starts off', function (): void {
    $this->tenantJson('GET', '/api/dashboard/settings')->assertOk()->assertJsonPath('data.pending_expiry_hours', null);

    $this->tenantJson('PUT', '/api/dashboard/settings', ['pending_expiry_hours' => 24])->assertOk()->assertJsonPath('data.pending_expiry_hours', 24);
    $this->tenantJson('PUT', '/api/dashboard/settings', ['pending_expiry_hours' => null])->assertOk()->assertJsonPath('data.pending_expiry_hours', null);
});

test('BR-INV07 the stale hours cannot be raised past the expiry already set', function (): void {
    $this->tenant->settings->update(['pending_expiry_hours' => 24]);

    $this->tenantJson('PUT', '/api/dashboard/settings', ['stale_pending_hours' => 30])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.pending_expiry_hours.0', 'يجب ألا تقل مهلة الإلغاء التلقائي عن مدة تمييز الطلب كمتأخر (30 ساعة)');

    $this->tenantJson('PUT', '/api/dashboard/settings', ['stale_pending_hours' => 30, 'pending_expiry_hours' => 30])->assertOk();
});

test('the automatic expiry is not shown on the storefront', function (): void {
    $this->tenantJson('GET', '/api/store/tenant')->assertOk()->assertJsonMissingPath('data.pending_expiry_hours');
});

test('BR-N03 an owner picks the statuses customers hear about and words a status, the default text sent back is dropped', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', [
        'order_notify_statuses' => ['delivered', 'confirmed'],
        'order_status_messages' => ['confirmed' => 'تأكد طلبك {رقم_الطلب}', 'delivered' => OrderStatusMessage::DEFAULTS['delivered'], 'cancelled' => null],
    ])
        ->assertOk()
        ->assertJsonPath('data.order_notify_statuses', ['confirmed', 'delivered'])
        ->assertJsonPath('data.order_status_messages', ['confirmed' => 'تأكد طلبك {رقم_الطلب}'])
        ->assertJsonPath('data.order_status_message_defaults.cancelled', OrderStatusMessage::DEFAULTS['cancelled']);
});

test('BR-N03 by default the four main statuses notify in the platform words', function (): void {
    $this->tenantJson('GET', '/api/dashboard/settings')
        ->assertOk()
        ->assertJsonPath('data.order_notify_statuses', ['confirmed', 'out_for_delivery', 'delivered', 'cancelled'])
        ->assertJsonPath('data.order_status_messages', []);
});

test('BR-C05 BR-C06 an owner sets the credit terms new customers start with, and clears them with null', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', ['default_payment_terms_days' => 30, 'default_credit_limit' => '2500'])
        ->assertOk()
        ->assertJsonPath('data.default_payment_terms_days', 30)
        ->assertJsonPath('data.default_credit_limit', '2500.00');

    $this->tenantJson('PUT', '/api/dashboard/settings', ['default_payment_terms_days' => null, 'default_credit_limit' => null])
        ->assertOk()
        ->assertJsonPath('data.default_payment_terms_days', null)
        ->assertJsonPath('data.default_credit_limit', null);
});

test('the Arabic message for a missing minimum order amount is explicit', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', ['mov_enabled' => true, 'mov_amount' => null])
        ->assertJsonPath('error.details.mov_amount.0', 'يجب تحديد الحد الأدنى لقيمة الطلب عند تفعيله');
});

test('BR-CU08 an owner sets the invitation text and the change is audited', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', ['invitation_message' => 'أهلًا {اسم_العميل}، اتفضل سجّل في {اسم_المتجر}'])
        ->assertOk()
        ->assertJsonPath('data.invitation_message', 'أهلًا {اسم_العميل}، اتفضل سجّل في {اسم_المتجر}')
        ->assertJsonPath('data.invitation_message_default', InvitationMessage::DEFAULT);

    $log = $this->asTenant($this->tenant, fn () => AuditLog::query()->sole());

    expect($log->action)->toBe('settings.updated')
        ->and($log->old_values)->toBe(['invitation_message' => null])
        ->and($log->new_values)->toBe(['invitation_message' => 'أهلًا {اسم_العميل}، اتفضل سجّل في {اسم_المتجر}']);
});

test('BR-CU08 sending the invitation text as null goes back to the default', function (): void {
    $this->tenant->settings->update(['invitation_message' => 'نص قديم']);

    $this->tenantJson('PUT', '/api/dashboard/settings', ['invitation_message' => null])
        ->assertOk()
        ->assertJsonPath('data.invitation_message', null);
});

test('the invitation text is not shown on the storefront', function (): void {
    $this->tenant->settings->update(['invitation_message' => 'نص خاص']);

    $this->tenantJson('GET', '/api/store/tenant')
        ->assertOk()
        ->assertJsonMissingPath('data.invitation_message')
        ->assertJsonMissingPath('data.invitation_message_default');
});

test('the Arabic message for an unknown invitation placeholder lists the allowed ones', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', ['invitation_message' => 'أهلًا {الاسم}'])
        ->assertJsonPath('error.details.invitation_message.0', 'متغير غير معروف: {الاسم}. المتغيرات المتاحة: {اسم_العميل}، {اسم_المتجر}، {عدد_الأيام}.');
});

test('BR-T03 settings are isolated per tenant', function (): void {
    $other = $this->otherTenant();

    $this->tenantJson('PUT', '/api/dashboard/settings', ['phone' => '+201111111111'])->assertOk();

    $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $this->tenant->id, 'phone' => '+201111111111']);
    $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $other->id, 'phone' => null]);
});

test('uploading a logo stores it under the tenant folder and replaces the previous file', function (): void {
    $first = $this->tenantJson('POST', '/api/dashboard/settings/logo', ['file' => UploadedFile::fake()->image('logo.png', 200, 200)])
        ->assertOk()
        ->json('data.logo_url');

    expect($first)->toContain("tenants/{$this->tenant->id}/branding/");

    $this->tenantJson('POST', '/api/dashboard/settings/logo', ['file' => UploadedFile::fake()->image('logo2.png', 200, 200)])
        ->assertOk();

    expect(Storage::disk('public')->allFiles("tenants/{$this->tenant->id}/branding"))->toHaveCount(1);
});

test('uploading a favicon is audited', function (): void {
    $this->tenantJson('POST', '/api/dashboard/settings/favicon', ['file' => UploadedFile::fake()->image('fav.png', 32, 32)])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'settings.favicon_changed']);
});

test('branding uploads reject non-images and unknown kinds', function (): void {
    $this->tenantJson('POST', '/api/dashboard/settings/logo', ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['file']]]);

    $this->tenantJson('POST', '/api/dashboard/settings/banner', ['file' => UploadedFile::fake()->image('x.png')])
        ->assertNotFound();
});
