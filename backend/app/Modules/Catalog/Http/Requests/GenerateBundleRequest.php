<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ReadsProductFilter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/bundles/generate (BR-P15, D93): `{ filter: { category?, f?, q? } }`, the product list's filter
 * (not empty, like BR-P12), from which a draft grade set is made.
 */
final class GenerateBundleRequest extends FormRequest
{
    use ReadsProductFilter;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['required', 'array'],
            ...$this->filterRules(),
        ];
    }

    /** The dashboard user making the set (products.created_by). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }

    protected function filterPrefix(): string
    {
        return 'filter.';
    }
}
