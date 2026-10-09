<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Tenancy\Enums\TenantUserStatus;
use App\Modules\Tenancy\Http\Requests\UpdateTenantUserRequest;

/** Partial update of a tenant user; null = not sent. */
final readonly class TenantUserChanges
{
    public function __construct(
        public ?string $name,
        public ?TenantUserStatus $status,
        public ?string $password,
    ) {}

    public static function fromRequest(UpdateTenantUserRequest $request): self
    {
        return new self(
            name: $request->name(),
            status: $request->status(),
            password: $request->password(),
        );
    }

    public function disables(): bool
    {
        return $this->status === TenantUserStatus::Disabled;
    }
}
