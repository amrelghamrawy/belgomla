<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Dashboard;

use App\Modules\Tenancy\Actions\AuthenticateTenantUser;
use App\Modules\Tenancy\Actions\CreateTenantUser;
use App\Modules\Tenancy\Actions\UpdateTenantUser;
use App\Modules\Tenancy\Data\TenantUserChanges;
use App\Modules\Tenancy\Data\TenantUserData;
use App\Modules\Tenancy\Http\Requests\StoreTenantUserRequest;
use App\Modules\Tenancy\Http\Requests\UpdateTenantUserRequest;
use App\Modules\Tenancy\Http\Resources\TenantUserResource;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Queries\TenantUsersQuery;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class UserController
{
    /** GET /api/dashboard/users */
    public function index(TenantUsersQuery $users): AnonymousResourceCollection
    {
        return TenantUserResource::collection($users->get());
    }

    /** POST /api/dashboard/users */
    public function store(StoreTenantUserRequest $request, CreateTenantUser $create): JsonResponse
    {
        $user = $create->handle(TenantUserData::fromRequest($request));

        return new TenantUserResource($user)->response()->setStatusCode(201);
    }

    /** PATCH /api/dashboard/users/{user} */
    public function update(UpdateTenantUserRequest $request, TenantUser $user, UpdateTenantUser $update): TenantUserResource
    {
        $actor = $request->user(AuthenticateTenantUser::GUARD);

        if (! $actor instanceof TenantUser) {
            throw new AuthenticationException;
        }

        return new TenantUserResource($update->handle($user, TenantUserChanges::fromRequest($request), $actor));
    }
}
