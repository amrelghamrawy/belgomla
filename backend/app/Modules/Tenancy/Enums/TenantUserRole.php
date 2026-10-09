<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * V1 has a single role (BR-T06). Granular roles are V2.
 */
enum TenantUserRole: string
{
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالك',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        return [
            self::Owner->value => self::Owner->label(),
        ];
    }
}
