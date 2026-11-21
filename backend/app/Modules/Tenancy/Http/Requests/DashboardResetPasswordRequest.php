<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/auth/reset-password: `{ email, code, password }` (D112, BR-T10). */
final class DashboardResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }

    public function email(): string
    {
        return mb_strtolower($this->string('email')->value());
    }

    public function code(): string
    {
        return $this->string('code')->value();
    }

    public function password(): string
    {
        return $this->string('password')->value();
    }
}
