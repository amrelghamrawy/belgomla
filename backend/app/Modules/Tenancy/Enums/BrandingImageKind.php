<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

enum BrandingImageKind: string
{
    case Logo = 'logo';
    case Favicon = 'favicon';

    /** The tenant_settings column that stores the file path. */
    public function column(): string
    {
        return $this->value.'_path';
    }

    public function label(): string
    {
        return match ($this) {
            self::Logo => 'الشعار',
            self::Favicon => 'أيقونة الموقع',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        return [
            self::Logo->value => self::Logo->label(),
            self::Favicon->value => self::Favicon->label(),
        ];
    }
}
