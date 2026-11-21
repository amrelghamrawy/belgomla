<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantUserStatus;
use App\Modules\Tenancy\Rules\EgyptianPhone;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantUserRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', new EgyptianPhone],
            'status' => ['sometimes', Rule::enum(TenantUserStatus::class)],
            'password' => ['sometimes', 'string', 'min:8', 'max:200'],
            'role' => ['sometimes', Rule::enum(TenantUserRole::class)],
        ];
    }

    public function name(): ?string
    {
        return $this->has('name') ? $this->string('name')->value() : null;
    }

    /** D112: whether `phone` was sent at all; `null` or an empty string clears it. */
    public function phoneSent(): bool
    {
        return $this->has('phone');
    }

    /** D112: the new mobile in E.164, or null to clear it. */
    public function phone(): ?string
    {
        return $this->filled('phone') ? PhoneNumber::toE164($this->string('phone')->value()) : null;
    }

    public function status(): ?TenantUserStatus
    {
        return $this->has('status') ? $this->enum('status', TenantUserStatus::class) : null;
    }

    public function role(): ?TenantUserRole
    {
        return $this->has('role') ? $this->enum('role', TenantUserRole::class) : null;
    }

    public function password(): ?string
    {
        return $this->has('password') ? $this->string('password')->value() : null;
    }
}
