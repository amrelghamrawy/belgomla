<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/expenses/{id}/void (D100 c): `{ reason }`, required, ≤ 300. */
final class VoidExpenseRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:300'],
        ];
    }

    public function reason(): string
    {
        return mb_trim($this->string('reason')->value());
    }

    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
