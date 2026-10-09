<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ArchiveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListCategoriesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ArchiveStatus::class)],
        ];
    }

    public function status(): ?ArchiveStatus
    {
        return $this->has('status') ? $this->enum('status', ArchiveStatus::class) : null;
    }
}
