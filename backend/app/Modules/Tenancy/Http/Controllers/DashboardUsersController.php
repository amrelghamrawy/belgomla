<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Tenancy\Models\TenantUser;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardUsersController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/dashboard/users */
    public function index(): JsonResponse
    {
        return ApiResponse::data(
            TenantUser::query()->orderBy('id')->get()->map->toApiArray()
        );
    }

    /** POST /api/dashboard/users */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $data['email'] = strtolower($data['email']);

        if (TenantUser::query()->where('email', $data['email'])->exists()) {
            throw ApiException::conflict('email_taken', 'هذا البريد الإلكتروني مستخدم بالفعل');
        }

        $user = TenantUser::create($data + ['role' => TenantUser::ROLE_OWNER]);
        $this->audit->log('tenant_user.created', $user, null, ['name' => $user->name, 'email' => $user->email]);

        return ApiResponse::created($user->toApiArray());
    }

    /** PATCH /api/dashboard/users/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = TenantUser::query()->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in([TenantUser::STATUS_ACTIVE, TenantUser::STATUS_DISABLED])],
            'password' => ['sometimes', 'string', 'min:8', 'max:200'],
        ]);

        if (($data['status'] ?? null) === TenantUser::STATUS_DISABLED && $user->is($request->user('tenant_user'))) {
            throw ApiException::conflict('cannot_disable_self', 'لا يمكنك تعطيل حسابك الحالي');
        }

        if (($data['status'] ?? null) === TenantUser::STATUS_DISABLED
            && TenantUser::query()->where('status', TenantUser::STATUS_ACTIVE)->where('id', '!=', $user->id)->doesntExist()) {
            throw ApiException::conflict('last_active_user', 'لا يمكن تعطيل آخر مستخدم نشط في المتجر');
        }

        $old = array_intersect_key($user->getAttributes(), array_flip(['name', 'status']));
        $user->fill($data)->save();
        $new = array_intersect_key($user->getAttributes(), array_flip(['name', 'status']));

        $this->audit->log('tenant_user.updated', $user, $old, $new + (isset($data['password']) ? ['password' => '***'] : []));

        return ApiResponse::data($user->toApiArray());
    }
}
