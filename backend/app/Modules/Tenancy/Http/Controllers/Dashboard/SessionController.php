<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Dashboard;

use App\Modules\Tenancy\Actions\AuthenticateTenantUser;
use App\Modules\Tenancy\Exceptions\LoginRejected;
use App\Modules\Tenancy\Http\Requests\DashboardLoginRequest;
use App\Modules\Tenancy\Http\Resources\TenantUserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final readonly class SessionController
{
    /** POST /api/dashboard/auth/login */
    public function store(DashboardLoginRequest $request, AuthenticateTenantUser $authenticate): TenantUserResource
    {
        if (! $request->hasSession()) {
            // Sanctum only starts a session for requests whose Origin/Referer is a stateful tenant host.
            throw LoginRejected::statefulRequestRequired();
        }

        $user = $authenticate->handle($request->email(), $request->password(), (string) $request->ip(), $request->session());

        return new TenantUserResource($user);
    }

    /** POST /api/dashboard/auth/logout */
    public function destroy(Request $request): JsonResponse
    {
        Auth::guard(AuthenticateTenantUser::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::noContent();
    }
}
