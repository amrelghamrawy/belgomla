<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\RelationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductRelationRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'related_product_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(RelationType::class)],
        ];
    }

    public function relatedProductId(): int
    {
        return $this->integer('related_product_id');
    }

    public function type(): RelationType
    {
        return $this->enum('type', RelationType::class) ?? RelationType::Companion;
    }
}
