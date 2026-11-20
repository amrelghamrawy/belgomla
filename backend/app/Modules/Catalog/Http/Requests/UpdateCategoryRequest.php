<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\CategoryIcon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT replaces name and parent: `parent_id` must be present (null = root). Changing it moves the
 * category with its whole subtree (BR-P06). The section (`section_id`, or the old
 * `kind` = its key until V3 Task 03) may be sent for a root only (D68, D109); none = keep.
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
            'section_id' => ['sometimes', 'nullable', 'integer', 'prohibited_unless:parent_id,null'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:40', 'prohibited_unless:parent_id,null'],
            'icon' => ['sometimes', 'nullable', Rule::enum(CategoryIcon::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'section_id.prohibited_unless' => 'القسم الفرعي يرث نوع القسم الرئيسي.',
            'kind.prohibited_unless' => 'القسم الفرعي يرث نوع القسم الرئيسي.',
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

    public function sectionId(): ?int
    {
        return $this->filled('section_id') ? $this->integer('section_id') : null;
    }

    /** The old section kind, read as a section key (V3 Task 02 until Task 03). */
    public function kind(): ?string
    {
        return $this->filled('kind') ? $this->string('kind')->value() : null;
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
