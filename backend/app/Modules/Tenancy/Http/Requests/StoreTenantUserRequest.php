<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Enums\TenantUserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTenantUserRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'role' => ['sometimes', Rule::enum(TenantUserRole::class)],
        ];
    }

    public function name(): string
    {
        return $this->string('name')->value();
    }

    public function email(): string
    {
        return mb_strtolower($this->string('email')->value());
    }

    /** BR-T06, D82: the new user's role; an owner when not given. */
    public function role(): TenantUserRole
    {
        return $this->enum('role', TenantUserRole::class) ?? TenantUserRole::Owner;
    }

    public function password(): string
    {
        return $this->string('password')->value();
    }
}
