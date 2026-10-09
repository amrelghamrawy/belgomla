<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/store/auth/invitation/{token}: `{ password }` (BR-CU08, D50). */
final class ActivateInvitationRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:'.config()->integer('customers.password_min'), 'max:200'],
        ];
    }

    public function password(): string
    {
        return $this->string('password')->value();
    }
}
