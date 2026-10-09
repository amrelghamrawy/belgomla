<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Dashboard;

use App\Modules\Tenancy\Actions\UpdateTenantSettings;
use App\Modules\Tenancy\Data\TenantSettingsData;
use App\Modules\Tenancy\Http\Requests\UpdateSettingsRequest;
use App\Modules\Tenancy\Http\Resources\TenantSettingsResource;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Modules\Tenancy\TenantContext;

final readonly class SettingsController
{
    /** GET /api/dashboard/settings */
    public function show(CurrentTenantSettingsQuery $settings): TenantSettingsResource
    {
        return new TenantSettingsResource($settings->get());
    }

    /** PUT /api/dashboard/settings */
    public function update(
        UpdateSettingsRequest $request,
        TenantContext $context,
        CurrentTenantSettingsQuery $settings,
        UpdateTenantSettings $update,
    ): TenantSettingsResource {
        $updated = $update->handle($context->get(), $settings->get(), TenantSettingsData::fromRequest($request));

        return new TenantSettingsResource($updated);
    }
}
