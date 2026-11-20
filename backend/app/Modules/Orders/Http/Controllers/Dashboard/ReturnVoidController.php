<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\VoidReturn;
use App\Modules\Orders\Http\Requests\VoidReturnRequest;
use App\Modules\Orders\Http\Resources\OrderReturnResource;
use App\Modules\Orders\Models\OrderReturn;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final readonly class ReturnVoidController
{
    /** POST /api/dashboard/returns/{id}/void — BR-R06, D76 */
    public function store(VoidReturnRequest $request, #[CurrentUser('tenant_user')] Authenticatable $user, OrderReturn $orderReturn, VoidReturn $void): OrderReturnResource
    {
        Gate::forUser($user)->authorize('void', $orderReturn);

        return new OrderReturnResource($void->handle($orderReturn, $request->reason(), $request->actorId())->load('items.orderItem'));
    }
}
