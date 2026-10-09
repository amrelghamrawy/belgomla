<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\SendOtp;
use App\Modules\Customers\Http\Requests\ResendOtpRequest;
use App\Modules\Customers\Http\Resources\OtpSentResource;

final readonly class OtpResendController
{
    /** POST /api/store/auth/resend-otp — BR-CU07, `channel=sms` is "send by SMS instead" */
    public function store(ResendOtpRequest $request, SendOtp $send): OtpSentResource
    {
        return new OtpSentResource($send->handle($request->phone(), $request->purpose(), $request->channel()));
    }
}
