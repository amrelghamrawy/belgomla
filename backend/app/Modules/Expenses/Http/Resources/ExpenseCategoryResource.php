<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Resources;

use App\Modules\Expenses\Enums\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One expense category (D100 b): its code, its label, and whether it needs a note.
 *
 * @property-read ExpenseCategory $resource
 */
final class ExpenseCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->value,
            'label' => $this->resource->label(),
            'needs_note' => $this->resource->needsNote(),
        ];
    }
}
