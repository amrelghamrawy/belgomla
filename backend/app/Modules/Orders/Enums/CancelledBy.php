<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Modules\Audit\Enums\ActorType;

/** BR-O02: who cancelled an order; `system` is the pending-order expiry (BR-INV07, D81). */
enum CancelledBy: string
{
    case Customer = 'customer';
    case TenantUser = 'tenant_user';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'العميل',
            self::TenantUser => 'المتجر',
            self::System => 'تلقائي',
        };
    }

    public function actorType(): ActorType
    {
        return match ($this) {
            self::Customer => ActorType::Customer,
            self::TenantUser => ActorType::TenantUser,
            self::System => ActorType::System,
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
