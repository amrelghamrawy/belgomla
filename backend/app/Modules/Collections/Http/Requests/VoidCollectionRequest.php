<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/collections/{id}/void: `{ reason }` (08-api-contract.md §10, BR-C02). */
final class VoidCollectionRequest extends FormRequest
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
