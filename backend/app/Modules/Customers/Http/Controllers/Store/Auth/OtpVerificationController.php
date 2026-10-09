<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\VerifyOtp;
use App\Modules\Customers\Exceptions\LoginRejected;
use App\Modules\Customers\Http\Requests\VerifyOtpRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class OtpVerificationController
{
    /**
     * POST /api/store/auth/verify-otp — BR-CU07. `register` answers with the customer (session started);
     * a `password_reset` check answers 204.
     */
    public function store(VerifyOtpRequest $request, VerifyOtp $verify): CustomerResource|JsonResponse
    {
        if (! $request->hasSession()) {
            // Sanctum only starts a session for requests whose Origin/Referer is a stateful tenant host.
            throw LoginRejected::statefulRequestRequired();
        }

        $customer = $verify->handle($request->phone(), $request->purpose(), $request->code(), $request->session());

        return $customer instanceof Customer ? new CustomerResource($customer) : ApiResponse::noContent();
    }
}
