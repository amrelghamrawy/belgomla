<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

/**
 * BR-CU05. Transitions (07-domain-model.md §11): self-registered `pending_verification` and merchant-created
 * `invited` become `active` (or `pending_approval` when the tenant requires approval, D2) once the phone is
 * proven; the merchant approves `pending_approval`; any status can be blocked, and unblocking restores it.
 */
enum CustomerStatus: string
{
    case PendingVerification = 'pending_verification';
    case Invited = 'invited';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'في انتظار التحقق',
            self::Invited => 'مدعو',
            self::PendingApproval => 'في انتظار الموافقة',
            self::Active => 'نشط',
            self::Blocked => 'محظور',
        };
    }

    /** BR-CU05: only active customers log in and order. */
    public function canLogin(): bool
    {
        return $this === self::Active;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PendingVerification, self::Invited => in_array($target, [self::Active, self::PendingApproval, self::Blocked], true),
            self::PendingApproval => $target === self::Active || $target === self::Blocked,
            self::Active => $target === self::Blocked,
            self::Blocked => $target !== self::Blocked,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
