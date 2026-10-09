<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Middleware\EnsureSessionTenant;
use App\Modules\Tenancy\Http\Requests\DashboardLoginRequest;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class DashboardAuthController
{
    public const GUARD = 'tenant_user';

    /** POST /api/dashboard/auth/login */
    public function login(DashboardLoginRequest $request, TenantContext $context): JsonResponse
    {
        if (! $request->hasSession()) {
            // Sanctum only starts a session for requests whose Origin/Referer is a stateful tenant host.
            throw new ApiException(400, 'stateful_request_required', 'يجب إرسال الطلب من واجهة المتجر');
        }

        $key = 'dashboard-login:'.$context->id().':'.strtolower($request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ApiException(429, 'rate_limited', 'محاولات كثيرة، حاول بعد دقيقة');
        }

        // TenantUser is tenant-scoped, so this can only find users of the current tenant.
        $user = TenantUser::query()->where('email', strtolower($request->input('email')))->first();

        if ($user === null || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($key, 60);

            throw ApiException::unprocessable('invalid_credentials', 'البريد الإلكتروني أو كلمة المرور غير صحيحة');
        }

        if (! $user->isActive()) {
            throw ApiException::forbidden('user_disabled', 'هذا الحساب معطّل');
        }

        RateLimiter::clear($key);

        Auth::guard(self::GUARD)->login($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureSessionTenant::SESSION_KEY, $context->id());

        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::data($user->toApiArray());
    }

    /** POST /api/dashboard/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard(self::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::noContent();
    }

    /** GET /api/dashboard/me */
    public function me(Request $request, TenantContext $context): JsonResponse
    {
        /** @var TenantUser $user */
        $user = $request->user(self::GUARD);
        $tenant = $context->get();

        return ApiResponse::data([
            'user' => $user->toApiArray(),
            'tenant' => [
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'status' => $tenant->status,
                'vertical' => $tenant->vertical,
            ],
        ]);
    }
}
