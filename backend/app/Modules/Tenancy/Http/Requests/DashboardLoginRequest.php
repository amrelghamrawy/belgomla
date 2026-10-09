<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardLoginRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ];
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
