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

    /** BR-CU08: the merchant's own invitation text, null for the default. Used by Customers. */
    public function invitationMessage(): ?string
    {
        $message = TenantSetting::query()->whereKey($this->context->id())->value('invitation_message');

        return is_string($message) ? $message : null;
    }

    /**
     * BR-C05, BR-C06: the credit limit and payment terms a new customer starts with (null = none). Used by Customers.
     *
     * @return array{credit_limit: string|null, payment_terms_days: int|null}
     */
    public function creditDefaults(): array
    {
        $settings = $this->get();

        return ['credit_limit' => $settings->default_credit_limit, 'payment_terms_days' => $settings->default_payment_terms_days];
    }

    /**
     * BR-N03: the statuses the store tells customers about, its own wording per status (a missing entry = the
     * default) and its name for the `{اسم_المتجر}` placeholder. Used by Orders.
     *
     * @return array{statuses: list<string>, messages: array<string, string|null>, store: string}
     */
    public function orderNotifications(): array
    {
        $settings = $this->get();

        return [
            'statuses' => $settings->order_notify_statuses,
            'messages' => $settings->order_status_messages ?? [],
            'store' => $settings->tenant->name,
        ];
    }

    /** D85: the most a sales user may take off an order line, in % ("10.00"); null = sales users cannot discount. Used by Orders. */
    public function maxDiscountPercentSales(): ?string
    {
        return $this->get()->max_discount_percent_sales;
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
