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

    /** BR-CU04 / D2: whether verified customers wait for the merchant's approval. Used by Customers. */
    public function requiresCustomerApproval(): bool
    {
        return TenantSetting::query()->whereKey($this->context->id())->value('require_customer_approval') === true;
    }

    /**
     * BR-MOV01: the minimum order value. Enabled without an amount counts as disabled.
     *
     * @return array{enabled: bool, amount: string|null}
     */
    public function minimumOrderValue(): array
    {
        $settings = $this->get();
        $enabled = $settings->mov_enabled && $settings->mov_amount !== null;

        return ['enabled' => $enabled, 'amount' => $enabled ? $settings->mov_amount : null];
    }
}
