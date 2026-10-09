<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Tenancy\Http\Requests\StoreTenantUserRequest;

final readonly class TenantUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    public static function fromRequest(StoreTenantUserRequest $request): self
    {
        return new self(
            name: $request->name(),
            email: $request->email(),
            password: $request->password(),
        );
    }
}
