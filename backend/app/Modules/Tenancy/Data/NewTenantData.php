<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

final readonly class NewTenantData
{
    /**
     * @param  string  $verticalKey  the key of an active platform vertical (D108), e.g. `libraries`
     * @param  string|null  $ownerPhone  an Egyptian mobile in any spelling PhoneNumber accepts; the owner's password
     *                                   reset code goes there (D112)
     */
    public function __construct(
        public string $subdomain,
        public string $name,
        public string $ownerEmail,
        public string $ownerPassword,
        public string $verticalKey,
        public ?string $ownerPhone = null,
    ) {}
}
