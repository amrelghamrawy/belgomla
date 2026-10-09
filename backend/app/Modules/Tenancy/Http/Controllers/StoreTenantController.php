<?php

declare(strict_types=1);

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

        return ApiResponse::data($tenant->settingsRow()->toPublicArray($tenant));
    }
}
