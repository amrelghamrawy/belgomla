<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Models\TenantUserOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/*
| D112, BR-T10: a dashboard user who forgot their password asks with their email, gets a 6-digit code on WhatsApp at
| the phone stored on their user, and sets a new password with it. The code follows BR-CU07 (10 minutes, 5 attempts,
| single use); every email gets the same answer (as D52 for customers).
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'old-secret', 'phone' => '+201012345678']);
});

function forgotDashboardPassword(string $email = 'owner@alnoor.test'): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/dashboard/auth/forgot-password', ['email' => $email]);
}

function resetDashboardPassword(string $code, string $password = 'new-secret', string $email = 'owner@alnoor.test'): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/dashboard/auth/reset-password', ['email' => $email, 'code' => $code, 'password' => $password]);
}

function dashboardPasswordIs(string $password): bool
{
    return Hash::check($password, TenantUser::withoutTenantScope()->where('email', 'owner@alnoor.test')->sole()->password);
}

test('D112 forgot password sends a code on WhatsApp to the user\'s phone', function (): void {
    forgotDashboardPassword()->assertNoContent();

    $message = OutboundMessage::withoutTenantScope()->sole();

    expect($message->to_phone)->toBe('+201012345678')
        ->and($message->purpose->value)->toBe('otp')
        ->and($message->channel->value)->toBe('whatsapp')
        ->and($message->body)->toContain('لوحة التحكم في مكتبة النور')
        ->and(lastOtpCode())->toMatch('/^\d{6}$/')
        ->and($this->inTenant(fn () => TenantUserOtp::query()->sole()->tenant_user_id))->toBe($this->owner->id);
});

test('D112 the email is matched without case', function (): void {
    forgotDashboardPassword('Owner@Alnoor.TEST')->assertNoContent();

    expect(OutboundMessage::withoutTenantScope()->count())->toBe(1);
});

test('D112 every email gets the same answer, and only an active user with a phone gets a code', function (string $email): void {
    $this->makeOwner(attributes: ['email' => 'nophone@alnoor.test']);
    $this->makeOwner(attributes: ['email' => 'disabled@alnoor.test', 'phone' => '+201112345678', 'status' => 'disabled']);

    forgotDashboardPassword($email)->assertNoContent();

    expect(OutboundMessage::withoutTenantScope()->count())->toBe(0)
        ->and(TenantUserOtp::withoutTenantScope()->count())->toBe(0);
})->with([
    'unknown email' => ['nobody@alnoor.test'],
    'user without a phone' => ['nophone@alnoor.test'],
    'disabled user' => ['disabled@alnoor.test'],
]);

test('BR-T03 a user of another tenant gets no code on this host', function (): void {
    $this->makeOwner($this->otherTenant(), ['email' => 'owner@other.test', 'phone' => '+201112345678']);

    forgotDashboardPassword('owner@other.test')->assertNoContent();

    expect(OutboundMessage::withoutTenantScope()->count())->toBe(0);
});

test('D112 at most 5 requests per email per hour', function (): void {
    foreach (range(1, 5) as $ignored) {
        forgotDashboardPassword()->assertNoContent();
    }

    forgotDashboardPassword()
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonStructure(['error' => ['details' => ['retry_after']]]);

    expect(OutboundMessage::withoutTenantScope()->count())->toBe(5);
});

test('D112 the code sets a new password, which then logs in', function (): void {
    forgotDashboardPassword()->assertNoContent();

    resetDashboardPassword(lastOtpCode())->assertNoContent();

    expect(dashboardPasswordIs('new-secret'))->toBeTrue();
    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'new-secret'])->assertOk();
});

test('D112 a reset rotates the remember token and is audited without the password', function (): void {
    $this->inTenant(fn () => $this->owner->forceFill(['remember_token' => 'old-token'])->save());
    forgotDashboardPassword()->assertNoContent();

    resetDashboardPassword(lastOtpCode())->assertNoContent();

    $audit = AuditLog::withoutTenantScope()->where('action', 'tenant_user.password_reset')->sole();

    expect(TenantUser::withoutTenantScope()->findOrFail($this->owner->id)->remember_token)->not->toBe('old-token')
        ->and($audit->entity_id)->toBe($this->owner->id)
        ->and($audit->new_values)->toBe(['password' => '***']);
});

test('D112 a reset code works once', function (): void {
    forgotDashboardPassword()->assertNoContent();
    $code = lastOtpCode();
    resetDashboardPassword($code)->assertNoContent();

    resetDashboardPassword($code, 'third-secret')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');

    expect(dashboardPasswordIs('new-secret'))->toBeTrue();
});

test('D112 a new code replaces the earlier one', function (): void {
    $this->inTenant(fn () => TenantUserOtp::factory()->code('111111')->create(['tenant_user_id' => $this->owner->id]));
    forgotDashboardPassword()->assertNoContent();

    resetDashboardPassword('111111')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');

    expect(TenantUserOtp::withoutTenantScope()->whereNull('consumed_at')->count())->toBe(1);
});

test('D112 a wrong code counts an attempt and keeps the old password', function (): void {
    $this->inTenant(fn () => TenantUserOtp::factory()->code('123123')->create(['tenant_user_id' => $this->owner->id]));

    resetDashboardPassword('999999')
        ->assertUnprocessable()
        ->assertExactJson(['error' => ['code' => 'otp_invalid', 'message' => 'رمز التحقق غير صحيح', 'details' => ['attempts_left' => 4]]]);

    expect(dashboardPasswordIs('old-secret'))->toBeTrue()
        ->and(TenantUserOtp::withoutTenantScope()->sole()->attempts)->toBe(1);
});

test('BR-CU07 after 5 wrong codes the code is dead, even the right one', function (): void {
    $this->inTenant(fn () => TenantUserOtp::factory()->code('123123')->create(['tenant_user_id' => $this->owner->id, 'attempts' => 5]));

    resetDashboardPassword('123123')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_attempts_exceeded');

    expect(dashboardPasswordIs('old-secret'))->toBeTrue();
});

test('BR-CU07 an expired code is refused', function (): void {
    $this->inTenant(fn () => TenantUserOtp::factory()->code('123123')->expired()->create(['tenant_user_id' => $this->owner->id]));

    resetDashboardPassword('123123')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_expired');
});

test('D112 a code is bound to its user', function (): void {
    $other = $this->makeOwner(attributes: ['email' => 'second@alnoor.test', 'phone' => '+201112345678']);
    $this->inTenant(fn () => TenantUserOtp::factory()->code('123123')->create(['tenant_user_id' => $other->id]));

    resetDashboardPassword('123123')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');

    expect(dashboardPasswordIs('old-secret'))->toBeTrue();
});

test('D112 a user disabled after asking cannot reset', function (): void {
    $this->inTenant(fn () => TenantUserOtp::factory()->code('123123')->create(['tenant_user_id' => $this->owner->id]));
    TenantUser::withoutTenantScope()->whereKey($this->owner->id)->update(['status' => 'disabled']);

    resetDashboardPassword('123123')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');
});

test('BR-T07 a suspended store refuses the reset like the login', function (): void {
    $this->tenant->update(['status' => App\Modules\Tenancy\Enums\TenantStatus::Suspended]);

    forgotDashboardPassword()
        ->assertForbidden()
        ->assertJsonPath('error.code', 'tenant_suspended');

    expect(OutboundMessage::withoutTenantScope()->count())->toBe(0);
});

test('reset-password validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/auth/reset-password', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'invalid email' => [['email' => 'x', 'code' => '123456', 'password' => 'new-secret'], 'email'],
    'missing code' => [['email' => 'owner@alnoor.test', 'password' => 'new-secret'], 'code'],
    'short code' => [['email' => 'owner@alnoor.test', 'code' => '123', 'password' => 'new-secret'], 'code'],
    'short password' => [['email' => 'owner@alnoor.test', 'code' => '123456', 'password' => 'short'], 'password'],
]);

test('forgot-password validation', function (): void {
    $this->tenantJson('POST', '/api/dashboard/auth/forgot-password', ['email' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['email']]]);
});
