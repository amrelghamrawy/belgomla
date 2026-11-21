<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Dashboard;

use App\Modules\Tenancy\Actions\RequestTenantUserPasswordReset;
use App\Modules\Tenancy\Http\Requests\DashboardForgotPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class PasswordForgotController
{
    /** POST /api/dashboard/auth/forgot-password — D112, BR-T10; the same answer for every email */
    public function store(DashboardForgotPasswordRequest $request, RequestTenantUserPasswordReset $reset): JsonResponse
    {
        $reset->handle($request->email());

        return ApiResponse::noContent();
    }
}
