<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/notifications/read: `{ ids: [] }` or `{ all: true }` (08-api-contract.md §12). */
final class MarkNotificationsReadRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'all' => ['sometimes', 'accepted', 'prohibits:ids'],
            'ids' => ['required_without:all', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * The notification ids to mark, or null for all of them.
     *
     * @return list<int>|null
     */
    public function ids(): ?array
    {
        if ($this->has('all')) {
            return null;
        }

        return array_values(array_map(intval(...), array_filter((array) $this->input('ids', []), is_numeric(...))));
    }
}
