<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\ResetPassword;
use App\Modules\Customers\Http\Requests\ResetPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class PasswordResetController
{
    /** POST /api/store/auth/reset-password — BR-CU04; the customer logs in afterwards */
    public function store(ResetPasswordRequest $request, ResetPassword $reset): JsonResponse
    {
        $reset->handle($request->phone(), $request->code(), $request->password());

        return ApiResponse::noContent();
    }
}
