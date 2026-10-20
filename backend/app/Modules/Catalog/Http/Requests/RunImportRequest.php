<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/products/import: `{ token }` from the preview of the file to import. */
final class RunImportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'uuid'],
        ];
    }

    public function token(): string
    {
        return mb_strtolower($this->string('token')->value());
    }

    /** The dashboard user starting the import (imports.created_by, the opening stock movements' actor). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
