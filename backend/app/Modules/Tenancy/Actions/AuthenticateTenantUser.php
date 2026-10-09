<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Exceptions\LoginRejected;
use App\Modules\Tenancy\Http\Middleware\EnsureSessionTenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Dashboard login: rate limit, credential check, status check, session binding to the tenant (Tech §6).
 */
final readonly class AuthenticateTenantUser
{
    public const string GUARD = 'tenant_user';

    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function __construct(private TenantContext $context) {}

    public function handle(string $email, string $password, string $ip, Session $session): TenantUser
    {
        $key = 'dashboard-login:'.$this->context->id().':'.$email.'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw LoginRejected::tooManyAttempts();
        }

        // TenantUser is tenant-scoped, so this can only find users of the current tenant.
        $user = TenantUser::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw LoginRejected::invalidCredentials();
        }

        if (! $user->isActive()) {
            throw LoginRejected::userDisabled();
        }

        RateLimiter::clear($key);

        Auth::guard(self::GUARD)->login($user);
        $session->regenerate();
        $session->put(EnsureSessionTenant::SESSION_KEY, $this->context->id());

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
