<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

enum ActorType: string
{
    case TenantUser = 'tenant_user';
    case Customer = 'customer';
    case System = 'system';

    /** The auth guard that authenticates this actor, if any. */
    public function guard(): ?string
    {
        return match ($this) {
            self::TenantUser => 'tenant_user',
            self::Customer => 'customer',
            self::System => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TenantUser => 'مستخدم المتجر',
            self::Customer => 'عميل',
            self::System => 'النظام',
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
