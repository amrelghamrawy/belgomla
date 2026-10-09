<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Support\Http\PaginatedRequest;

/** GET /api/dashboard/notifications: newest first, `?unread=1` (08-api-contract.md §12). */
final class ListNotificationsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'unread' => ['sometimes', 'boolean'],
        ];
    }

    public function unreadOnly(): bool
    {
        return $this->boolean('unread');
    }
}
