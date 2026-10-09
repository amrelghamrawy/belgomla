<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Dashboard;

use App\Modules\Tenancy\Actions\AuthenticateTenantUser;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Http\Resources\TenantUserResource;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class MeController
{
    /** GET /api/dashboard/me — the signed-in user and their tenant. */
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        $user = $request->user(AuthenticateTenantUser::GUARD);

        if (! $user instanceof TenantUser) {
            throw new AuthenticationException;
        }

        return response()->json(['data' => [
            'user' => new TenantUserResource($user)->resolve($request),
            'tenant' => new TenantResource($context->get())->resolve($request),
        ]]);
    }
}
