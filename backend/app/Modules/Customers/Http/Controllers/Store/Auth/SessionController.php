<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\LoginCustomer;
use App\Modules\Customers\Exceptions\LoginRejected;
use App\Modules\Customers\Http\Requests\CustomerLoginRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Services\CustomerSession;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class SessionController
{
    /** POST /api/store/auth/login — BR-CU04, BR-CU05 */
    public function store(CustomerLoginRequest $request, LoginCustomer $login): CustomerResource
    {
        if (! $request->hasSession()) {
            throw LoginRejected::statefulRequestRequired();
        }

        return new CustomerResource($login->handle($request->phone(), $request->password(), (string) $request->ip(), $request->session()));
    }

    /** POST /api/store/auth/logout */
    public function destroy(Request $request, CustomerSession $session): JsonResponse
    {
        $session->end($request->session());

        return ApiResponse::noContent();
    }
}
