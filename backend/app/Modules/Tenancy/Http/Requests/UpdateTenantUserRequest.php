<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Enums\TenantUserStatus;
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
            'status' => ['sometimes', Rule::enum(TenantUserStatus::class)],
            'password' => ['sometimes', 'string', 'min:8', 'max:200'],
        ];
    }

    public function name(): ?string
    {
        return $this->has('name') ? $this->string('name')->value() : null;
    }

    public function status(): ?TenantUserStatus
    {
        return $this->has('status') ? $this->enum('status', TenantUserStatus::class) : null;
    }

    public function password(): ?string
    {
        return $this->has('password') ? $this->string('password')->value() : null;
    }
}
