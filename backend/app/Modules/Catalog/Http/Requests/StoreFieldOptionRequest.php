<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFieldOptionRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function label(): string
    {
        return mb_trim($this->string('label')->value());
    }

    public function sortOrder(): ?int
    {
        return $this->has('sort_order') ? $this->integer('sort_order') : null;
    }
}
