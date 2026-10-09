<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/store|dashboard/orders/{number}/cancel: `{ reason }` (BR-O02). */
final class CancelOrderRequest extends FormRequest
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
