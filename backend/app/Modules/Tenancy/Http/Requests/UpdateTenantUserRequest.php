<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantUserRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in([TenantUser::STATUS_ACTIVE, TenantUser::STATUS_DISABLED])],
            'password' => ['sometimes', 'string', 'min:8', 'max:200'],
        ];
    }

    public function name(): ?string
    {
        return $this->has('name') ? $this->string('name')->value() : null;
    }

    public function status(): ?string
    {
        return $this->has('status') ? $this->string('status')->value() : null;
    }

    public function password(): ?string
    {
        return $this->has('password') ? $this->string('password')->value() : null;
    }

    public function disables(): bool
    {
        return $this->status() === TenantUser::STATUS_DISABLED;
    }
}
