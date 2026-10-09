<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Tenancy\Enums\Vertical;

final readonly class NewTenantData
{
    public function __construct(
        public string $subdomain,
        public string $name,
        public string $ownerEmail,
        public string $ownerPassword,
        public Vertical $vertical,
    ) {}
}
