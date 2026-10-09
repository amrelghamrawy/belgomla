<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Body is a top-level list: `[ {id, sort_order}, ... ]` (08-api-contract.md §6). */
final class ReorderCategoriesRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            '*' => ['array:id,sort_order'],
            '*.id' => ['required', 'integer', 'distinct'],
            '*.sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<int, int> category id => sort order
     */
    public function positions(): array
    {
        $positions = [];

        foreach ($this->all() as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null) && is_numeric($row['sort_order'] ?? null)) {
                $positions[(int) $row['id']] = (int) $row['sort_order'];
            }
        }

        return $positions;
    }
}
