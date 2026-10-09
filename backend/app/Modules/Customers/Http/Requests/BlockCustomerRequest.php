<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/customers/{id}/block: `{ reason }` (BR-CU05). */
final class BlockCustomerRequest extends FormRequest
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
}
