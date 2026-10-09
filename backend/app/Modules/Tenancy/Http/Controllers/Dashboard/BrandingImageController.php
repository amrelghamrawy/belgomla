<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Dashboard;

use App\Modules\Tenancy\Actions\UploadBrandingImage;
use App\Modules\Tenancy\Enums\BrandingImageKind;
use App\Modules\Tenancy\Http\Requests\UploadBrandingImageRequest;
use App\Modules\Tenancy\Http\Resources\TenantSettingsResource;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Modules\Tenancy\TenantContext;

final readonly class BrandingImageController
{
    /** POST /api/dashboard/settings/{kind}  (kind = logo | favicon) */
    public function store(
        UploadBrandingImageRequest $request,
        BrandingImageKind $kind,
        TenantContext $context,
        CurrentTenantSettingsQuery $settings,
        UploadBrandingImage $upload,
    ): TenantSettingsResource {
        $updated = $upload->handle($context->get(), $settings->get(), $kind, $request->image());

        return new TenantSettingsResource($updated);
    }
}
