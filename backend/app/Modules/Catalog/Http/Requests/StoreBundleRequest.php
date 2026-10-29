<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\BundlePayloadRules;
use App\Modules\Catalog\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/dashboard/bundles (BR-P15, D93): a bundle product, its SKU, components and pricing in one call; `status`
 * active (default) or inactive.
 */
final class StoreBundleRequest extends FormRequest
{
    use BundlePayloadRules;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->bundleRules(),
            'status' => ['sometimes', Rule::in([ProductStatus::Active->value, ProductStatus::Inactive->value])],
        ];
    }

    public function status(): ?ProductStatus
    {
        return $this->has('status') ? $this->enum('status', ProductStatus::class) : null;
    }

    /** The dashboard user creating the bundle (products.created_by). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
