<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetProductStatusRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ProductStatus::class)],
        ];
    }

    public function status(): ProductStatus
    {
        return $this->enum('status', ProductStatus::class) ?? ProductStatus::Active;
    }
}
