<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT replaces name and parent: `parent_id` must be present (null = root). Changing it moves the
 * category with its whole subtree (BR-P06).
 */
final class UpdateCategoryRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['present', 'nullable', 'integer'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    public function parentId(): ?int
    {
        return $this->filled('parent_id') ? $this->integer('parent_id') : null;
    }

    public function sortOrder(): ?int
    {
        return $this->has('sort_order') ? $this->integer('sort_order') : null;
    }
}
