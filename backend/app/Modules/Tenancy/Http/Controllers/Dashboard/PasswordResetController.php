<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Dashboard;

use App\Modules\Tenancy\Actions\ResetTenantUserPassword;
use App\Modules\Tenancy\Http\Requests\DashboardResetPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class PasswordResetController
{
    /** POST /api/dashboard/auth/reset-password — D112, BR-T10; the user logs in afterwards */
    public function store(DashboardResetPasswordRequest $request, ResetTenantUserPassword $reset): JsonResponse
    {
        $reset->handle($request->email(), $request->code(), $request->password());

        return ApiResponse::noContent();
    }
}
