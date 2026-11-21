<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/auth/forgot-password: `{ email }` (D112, BR-T10). */
final class DashboardForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
        ];
    }

    public function email(): string
    {
        return mb_strtolower($this->string('email')->value());
    }
}
