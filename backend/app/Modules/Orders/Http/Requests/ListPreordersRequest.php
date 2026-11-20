<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\PreorderState;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rules\Enum;

/** GET /api/dashboard/preorders: `?product_id=&status=open|converted|cancelled&page=&per_page=` (BR-O14, D88). */
final class ListPreordersRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', new Enum(PreorderState::class)],
        ];
    }

    public function productId(): ?int
    {
        return $this->has('product_id') ? $this->integer('product_id') : null;
    }

    public function state(): ?PreorderState
    {
        return $this->enum('status', PreorderState::class);
    }
}
