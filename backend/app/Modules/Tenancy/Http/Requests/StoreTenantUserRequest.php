<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantUserRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
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

    public function password(): string
    {
        return $this->string('password')->value();
    }
}
