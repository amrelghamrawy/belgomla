<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoreTenantController
{
    /** GET /api/store/tenant */
    public function show(TenantContext $context): JsonResponse
    {
        $tenant = $context->get();
        $settings = $tenant->settings ?? $tenant->settings()->make();

        return ApiResponse::data($settings->toPublicArray($tenant));
    }
}
