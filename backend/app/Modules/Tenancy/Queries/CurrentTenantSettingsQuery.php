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

    /** BR-CU04 / D1: whether guests must log in to see prices. Used by Catalog's storefront. */
    public function requiresLoginForPrices(): bool
    {
        return TenantSetting::query()->whereKey($this->context->id())->value('require_login_for_prices') === true;
    }
}
