<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Testing;

use App\Modules\Messaging\Http\Requests\ShowLastOtpRequest;
use App\Modules\Messaging\Queries\LastOtpCodeQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class LastOtpController
{
    /** GET /api/_test/last-otp — the newest OTP sent to `phone` on this tenant (D62, local/testing only). */
    public function show(ShowLastOtpRequest $request, LastOtpCodeQuery $query): JsonResponse
    {
        return ApiResponse::data(['code' => $query->get($request->phone())]);
    }
}
