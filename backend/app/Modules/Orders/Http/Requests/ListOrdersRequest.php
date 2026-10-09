<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rules\Enum;

/** GET /api/store/orders: the customer's orders, newest first, `?status=` (08-api-contract.md §3). */
final class ListOrdersRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['sometimes', 'string', new Enum(OrderStatus::class)],
        ];
    }

    public function status(): ?OrderStatus
    {
        return $this->enum('status', OrderStatus::class);
    }
}
