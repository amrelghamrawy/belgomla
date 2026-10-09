<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * D1 / BR-CU04: prices are public unless the tenant turned on `require_login_for_prices`; then only
 * a logged-in customer sees them. The customer guard arrives with Task 5, until then every storefront
 * visitor is a guest.
 */
final readonly class PriceVisibilityQuery
{
    public function __construct(private CurrentTenantSettingsQuery $settings) {}

    public function hiddenFor(?Authenticatable $customer): bool
    {
        return ! $customer instanceof Authenticatable && $this->settings->requiresLoginForPrices();
    }
}
