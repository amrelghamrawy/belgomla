<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Store;

use App\Modules\Tenancy\Http\Resources\TenantSettingsResource;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;

final readonly class TenantController
{
    /** GET /api/store/tenant — branding and ordering settings of the store on this host. */
    public function show(CurrentTenantSettingsQuery $settings): TenantSettingsResource
    {
        return new TenantSettingsResource($settings->get());
    }
}
