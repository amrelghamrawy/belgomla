<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Pricing\Enums\PromotionStatus;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rule;

/** GET /api/dashboard/promotions?status=&page=&per_page= (BR-PR15, V2 Task 12). */
final class ListPromotionsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['sometimes', Rule::enum(PromotionStatus::class)],
        ];
    }

    public function status(): ?PromotionStatus
    {
        return $this->enum('status', PromotionStatus::class);
    }
}
