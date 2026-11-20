<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/products/{id}/convert-preorders: no body; the dashboard user is the actor (BR-O14). */
final class ConvertPreordersRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /** The dashboard user converting, the actor of the new orders and their history. */
    public function actorId(): int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : 0;
    }
}
