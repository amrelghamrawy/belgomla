<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/products/{id}/duplicate takes no body; this carries the acting user. */
final class DuplicateProductRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
