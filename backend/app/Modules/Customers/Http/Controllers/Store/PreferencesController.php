<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store;

use App\Modules\Customers\Actions\UpdateNotificationPreferences;
use App\Modules\Customers\Http\Requests\UpdatePreferencesRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use Illuminate\Container\Attributes\CurrentUser;

final readonly class PreferencesController
{
    /** PATCH /api/store/account/preferences — BR-N03: answers the customer like `GET /me` */
    public function update(UpdatePreferencesRequest $request, #[CurrentUser('customer')] Customer $customer, UpdateNotificationPreferences $update): CustomerResource
    {
        return new CustomerResource($update->handle($customer, $request->orderNotifications()));
    }
}
