<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `{ ids: [imageId, ...] }` in the new display order; must list every image of the product. */
final class ReorderProductImagesRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = $this->input('ids', []);

        return is_array($ids) ? array_values(array_map(intval(...), array_filter($ids, is_numeric(...)))) : [];
    }
}
