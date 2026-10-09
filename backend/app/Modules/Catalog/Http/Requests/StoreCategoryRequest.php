<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class StoreCategoryRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'image' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
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

    public function image(): ?UploadedFile
    {
        $file = $this->file('image');

        return $file instanceof UploadedFile ? $file : null;
    }
}
