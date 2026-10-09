<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\RequestPasswordReset;
use App\Modules\Customers\Http\Requests\ForgotPasswordRequest;
use App\Modules\Customers\Http\Resources\OtpSentResource;

final readonly class PasswordForgotController
{
    /** POST /api/store/auth/forgot-password — BR-CU04, same answer for unknown phones (D52) */
    public function store(ForgotPasswordRequest $request, RequestPasswordReset $reset): OtpSentResource
    {
        return new OtpSentResource($reset->handle($request->phone()));
    }
}
