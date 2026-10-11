<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\CategoryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/** `kind` may be sent for a root only; a child inherits its parent's kind (D68). */
final class StoreCategoryRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer'],
            'kind' => ['sometimes', 'nullable', 'prohibited_unless:parent_id,null', Rule::enum(CategoryKind::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'image' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
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

    public function sortOrder(): ?int
    {
        return $this->has('sort_order') ? $this->integer('sort_order') : null;
    }

    public function image(): ?UploadedFile
    {
        $file = $this->file('image');

        return $file instanceof UploadedFile ? $file : null;
    }
}
