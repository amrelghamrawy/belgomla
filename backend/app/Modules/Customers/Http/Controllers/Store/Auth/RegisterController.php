<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\RegisterCustomer;
use App\Modules\Customers\Data\RegistrationData;
use App\Modules\Customers\Http\Requests\RegisterCustomerRequest;
use App\Modules\Customers\Http\Resources\OtpSentResource;

final readonly class RegisterController
{
    /** POST /api/store/auth/register — BR-CU04, BR-CU07, BR-CU08 */
    public function store(RegisterCustomerRequest $request, RegisterCustomer $register): OtpSentResource
    {
        return new OtpSentResource($register->handle(RegistrationData::fromRequest($request)));
    }
}
