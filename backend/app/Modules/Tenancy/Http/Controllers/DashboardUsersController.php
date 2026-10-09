<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Tenancy\Http\Requests\StoreTenantUserRequest;
use App\Modules\Tenancy\Http\Requests\UpdateTenantUserRequest;
use App\Modules\Tenancy\Models\TenantUser;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardUsersController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/dashboard/users */
    public function index(): JsonResponse
    {
        return ApiResponse::data(
            TenantUser::query()->orderBy('id')->get()->map(fn (TenantUser $user): array => $user->toApiArray())
        );
    }

    /** POST /api/dashboard/users */
    public function store(StoreTenantUserRequest $request): JsonResponse
    {
        $email = $request->email();

        if (TenantUser::query()->where('email', $email)->exists()) {
            throw ApiException::conflict('email_taken', 'هذا البريد الإلكتروني مستخدم بالفعل');
        }

        $user = TenantUser::create([
            'name' => $request->name(),
            'email' => $email,
            'password' => $request->password(),
            'role' => TenantUser::ROLE_OWNER,
        ]);

        $this->audit->log('tenant_user.created', $user, null, ['name' => $user->name, 'email' => $user->email]);

        return ApiResponse::created($user->toApiArray());
    }

    /** PATCH /api/dashboard/users/{id} */
    public function update(UpdateTenantUserRequest $request, int $id): JsonResponse
    {
        $user = TenantUser::query()->findOrFail($id);
        $actor = $request->user('tenant_user');

        if ($request->disables() && $actor !== null && $user->is($actor)) {
            throw ApiException::conflict('cannot_disable_self', 'لا يمكنك تعطيل حسابك الحالي');
        }

        if ($request->disables()
            && TenantUser::query()->where('status', TenantUser::STATUS_ACTIVE)->where('id', '!=', $user->id)->doesntExist()) {
            throw ApiException::conflict('last_active_user', 'لا يمكن تعطيل آخر مستخدم نشط في المتجر');
        }

        $changes = array_filter([
            'name' => $request->name(),
            'status' => $request->status(),
            'password' => $request->password(),
        ], fn (?string $value): bool => $value !== null);

        $old = array_intersect_key($user->getAttributes(), array_flip(['name', 'status']));
        $user->fill($changes)->save();
        $new = array_intersect_key($user->getAttributes(), array_flip(['name', 'status']));

        if (array_key_exists('password', $changes)) {
            $new['password'] = '***';
        }

        $this->audit->log('tenant_user.updated', $user, $old, $new);

        return ApiResponse::data($user->toApiArray());
    }
}
