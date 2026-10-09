<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ProductPayloadRules;
use App\Modules\Catalog\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/dashboard/products: the product and its single SKU in one call (BR-P02). */
final class StoreProductRequest extends FormRequest
{
    use ProductPayloadRules;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->productRules(),
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
        ];
    }

    public function status(): ?ProductStatus
    {
        return $this->has('status') ? $this->enum('status', ProductStatus::class) : null;
    }

    /** The dashboard user creating the product (products.created_by). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
