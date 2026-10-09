<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Suspended => 'موقوف',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        return [
            self::Active->value => self::Active->label(),
            self::Suspended->value => self::Suspended->label(),
        ];
    }
}
