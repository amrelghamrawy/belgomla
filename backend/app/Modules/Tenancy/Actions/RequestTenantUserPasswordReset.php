<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Contracts\ResetCodeSender;
use App\Modules\Tenancy\Exceptions\ResetCodeRejected;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Models\TenantUserOtp;
use App\Modules\Tenancy\Services\OtpCodes;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;

/**
 * D112, BR-T10: "forgot password" on the dashboard sends a 6-digit code on WhatsApp to the phone stored on the user.
 * Only an active user with a phone gets one, but every email gets the same answer, so the endpoint does not reveal
 * which emails have accounts (as D52 does for customers). At most 5 requests per email per hour, counted per tenant;
 * a new code replaces the earlier live ones. Owns its transaction.
 */
final readonly class RequestTenantUserPasswordReset
{
    public function __construct(
        private OtpCodes $codes,
        private ResetCodeSender $sender,
        private RateLimiter $limiter,
        private TenantContext $context,
    ) {}

    public function handle(string $email): void
    {
        $key = 'dashboard-reset:'.$this->context->id().':'.$email;

        if ($this->limiter->tooManyAttempts($key, config()->integer('tenancy.password_reset.sends_per_hour'))) {
            throw ResetCodeRejected::hourlyLimit($this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, 3600);

        // TenantUser is tenant-scoped, so only users of the current tenant are found.
        $user = TenantUser::query()->where('email', $email)->first();

        if (! $user instanceof TenantUser || ! $user->isActive() || $user->phone === null) {
            return;
        }

        DB::transaction(function () use ($user): void {
            TenantUserOtp::query()
                ->where('tenant_user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = $this->codes->generate();

            TenantUserOtp::query()->create([
                'tenant_user_id' => $user->id,
                'code_hash' => $this->codes->hash($code),
                'expires_at' => now()->addMinutes(config()->integer('tenancy.password_reset.ttl_minutes')),
            ]);

            $this->sender->send((string) $user->phone, $this->text($code));
        });
    }

    private function text(string $code): string
    {
        $minutes = config()->integer('tenancy.password_reset.ttl_minutes');

        return "رمز استعادة كلمة مرور لوحة التحكم في {$this->context->get()->name}: {$code}\nصالح لمدة {$minutes} دقائق. لا تشاركه مع أحد.";
    }
}
