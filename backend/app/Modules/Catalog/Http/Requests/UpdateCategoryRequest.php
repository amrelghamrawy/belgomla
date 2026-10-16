<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\CategoryIcon;
use App\Modules\Catalog\Enums\CategoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT replaces name and parent: `parent_id` must be present (null = root). Changing it moves the
 * category with its whole subtree (BR-P06). `kind` may be sent for a root only (D68).
 */
final class UpdateCategoryRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['present', 'nullable', 'integer'],
            'kind' => ['sometimes', 'nullable', 'prohibited_unless:parent_id,null', Rule::enum(CategoryKind::class)],
            'icon' => ['sometimes', 'nullable', Rule::enum(CategoryIcon::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['kind.prohibited_unless' => 'القسم الفرعي يرث نوع القسم الرئيسي.'];
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    public function parentId(): ?int
    {
        return $this->filled('parent_id') ? $this->integer('parent_id') : null;
    }

    public function kind(): ?CategoryKind
    {
        return $this->filled('kind') ? $this->enum('kind', CategoryKind::class) : null;
    }

    /** Null = no icon (D73); whether it was sent at all is `has('icon')`. */
    public function icon(): ?CategoryIcon
    {
        return $this->filled('icon') ? $this->enum('icon', CategoryIcon::class) : null;
    }

    public function sortOrder(): ?int
    {
        return $this->has('sort_order') ? $this->integer('sort_order') : null;
    }
}
