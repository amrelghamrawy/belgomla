<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Queries;

use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;

/** The settings row of the tenant in context. Provisioning guarantees it exists. */
final readonly class CurrentTenantSettingsQuery
{
    public function __construct(private TenantContext $context) {}

    public function get(): TenantSetting
    {
        return TenantSetting::query()->whereKey($this->context->id())->firstOrFail();
    }
}
