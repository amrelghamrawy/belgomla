<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /api/store/account/preferences: `{ order_notifications }` (08-api-contract.md §3, BR-N03). */
final class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'order_notifications' => ['required', 'boolean'],
        ];
    }

    public function orderNotifications(): bool
    {
        return $this->boolean('order_notifications');
    }
}
