<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Tenancy\Http\Requests\UpdateSettingsRequest;

/**
 * Partial update: every field is null when the request did not send it.
 */
final readonly class TenantSettingsData
{
    /**
     * @param  array<int, array{day: int, from: string, to: string}>|null  $workingHours
     * @param  list<string>  $sent
     */
    public function __construct(
        public ?string $name,
        public ?string $primaryColor,
        public ?string $secondaryColor,
        public ?string $phone,
        public ?string $whatsapp,
        public ?string $address,
        public ?string $description,
        public ?array $workingHours,
        public ?bool $movEnabled,
        public ?string $movAmount,
        public ?bool $requireLoginForPrices,
        public ?bool $requireCustomerApproval,
        public ?int $stalePendingHours,
        public ?string $invitationMessage,
        /** Fields the request explicitly sent, so nullable ones can be cleared. */
        public array $sent,
        public ?int $defaultPaymentTermsDays = null,
        public ?string $defaultCreditLimit = null,
        /** @var list<string>|null */
        public ?array $orderNotifyStatuses = null,
        /** @var array<string, string|null>|null */
        public ?array $orderStatusMessages = null,
        public ?int $pendingExpiryHours = null,
        public ?string $maxDiscountPercentSales = null,
    ) {}

    public static function fromRequest(UpdateSettingsRequest $request): self
    {
        return new self(
            name: $request->storeName(),
            primaryColor: $request->color('primary_color'),
            secondaryColor: $request->color('secondary_color'),
            phone: $request->nullableString('phone'),
            whatsapp: $request->nullableString('whatsapp'),
            address: $request->nullableString('address'),
            description: $request->nullableString('description'),
            workingHours: $request->workingHours(),
            movEnabled: $request->nullableBoolean('mov_enabled'),
            movAmount: $request->movAmount(),
            requireLoginForPrices: $request->nullableBoolean('require_login_for_prices'),
            requireCustomerApproval: $request->nullableBoolean('require_customer_approval'),
            stalePendingHours: $request->stalePendingHours(),
            invitationMessage: $request->nullableString('invitation_message'),
            sent: $request->sentKeys(),
            defaultPaymentTermsDays: $request->nullableInteger('default_payment_terms_days'),
            defaultCreditLimit: $request->nullableMoney('default_credit_limit'),
            orderNotifyStatuses: $request->orderNotifyStatuses(),
            orderStatusMessages: $request->orderStatusMessages(),
            pendingExpiryHours: $request->pendingExpiryHours(),
            maxDiscountPercentSales: $request->nullableMoney('max_discount_percent_sales'),
        );
    }

    /**
     * Column => value for every setting the request sent (name excluded, it lives on tenants).
     *
     * @return array<string, mixed>
     */
    public function settingColumns(): array
    {
        $map = [
            'primary_color' => $this->primaryColor,
            'secondary_color' => $this->secondaryColor,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'address' => $this->address,
            'description' => $this->description,
            'working_hours' => $this->workingHours,
            'mov_enabled' => $this->movEnabled,
            'mov_amount' => $this->movAmount,
            'require_login_for_prices' => $this->requireLoginForPrices,
            'require_customer_approval' => $this->requireCustomerApproval,
            'stale_pending_hours' => $this->stalePendingHours,
            'invitation_message' => $this->invitationMessage,
            'default_payment_terms_days' => $this->defaultPaymentTermsDays,
            'default_credit_limit' => $this->defaultCreditLimit,
            'order_notify_statuses' => $this->orderNotifyStatuses,
            'order_status_messages' => $this->orderStatusMessages === [] ? null : $this->orderStatusMessages,
            'pending_expiry_hours' => $this->pendingExpiryHours,
            'max_discount_percent_sales' => $this->maxDiscountPercentSales,
        ];

        return array_intersect_key($map, array_flip($this->sent));
    }
}
