<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

enum TenantUserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Disabled => 'معطّل',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        return [
            self::Active->value => self::Active->label(),
            self::Disabled->value => self::Disabled->label(),
        ];
    }
}
