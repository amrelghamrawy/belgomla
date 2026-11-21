<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantUserStatus;
use App\Modules\Tenancy\Http\Requests\UpdateTenantUserRequest;

/** Partial update of a tenant user; null = not sent, except `phone`, which can be cleared (`phoneSent`, D112). */
final readonly class TenantUserChanges
{
    public function __construct(
        public ?string $name,
        public ?TenantUserStatus $status,
        public ?string $password,
        public ?TenantUserRole $role = null,
        public ?string $phone = null,
        public bool $phoneSent = false,
    ) {}

    public static function fromRequest(UpdateTenantUserRequest $request): self
    {
        return new self(
            name: $request->name(),
            status: $request->status(),
            password: $request->password(),
            role: $request->role(),
            phone: $request->phone(),
            phoneSent: $request->phoneSent(),
        );
    }

    public function disables(): bool
    {
        return $this->status === TenantUserStatus::Disabled;
    }
}
