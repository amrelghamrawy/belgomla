<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Exceptions\ResetCodeRejected;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Models\TenantUserOtp;
use App\Modules\Tenancy\Services\OtpCodes;
use Illuminate\Support\Str;

/**
 * D112, BR-T10: sets a new dashboard password with the code sent by RequestTenantUserPasswordReset, using the code
 * up; the user logs in afterwards. The code is checked like a customer code (BR-CU07): the latest live one, valid
 * 10 minutes, 5 attempts. A wrong code counts an attempt that must survive the refusal, so nothing here runs in a
 * transaction; consuming is atomic, so of two requests with the same code only one succeeds. The remember token
 * is rotated so "remember me" cookies made with the old password stop working. Audited `tenant_user.password_reset`.
 */
final readonly class ResetTenantUserPassword
{
    public function __construct(private OtpCodes $codes, private AuditLogger $audit) {}

    public function handle(string $email, string $code, string $password): TenantUser
    {
        $user = TenantUser::query()->where('email', $email)->first();

        if (! $user instanceof TenantUser || ! $user->isActive()) {
            throw ResetCodeRejected::noLiveCode();
        }

        $this->consume($user, $code);

        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        $this->audit->log('tenant_user.password_reset', $user, null, ['password' => '***']);

        return $user;
    }

    private function consume(TenantUser $user, string $code): void
    {
        $otp = TenantUserOtp::query()
            ->where('tenant_user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp instanceof TenantUserOtp) {
            throw ResetCodeRejected::noLiveCode();
        }

        if ($otp->expires_at->isPast()) {
            throw ResetCodeRejected::expired();
        }

        $maxAttempts = config()->integer('tenancy.password_reset.max_attempts');

        if ($otp->attempts >= $maxAttempts) {
            throw ResetCodeRejected::attemptsExceeded();
        }

        if (! $this->codes->matches($code, $otp->code_hash)) {
            $otp->increment('attempts');

            throw ResetCodeRejected::wrongCode(max(0, $maxAttempts - $otp->attempts));
        }

        if (TenantUserOtp::query()->whereKey($otp->id)->whereNull('consumed_at')->update(['consumed_at' => now()]) !== 1) {
            throw ResetCodeRejected::noLiveCode();
        }
    }
}
