<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\BundlePayloadRules;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/dashboard/bundles/{product} (BR-P15, D93): replaces the bundle's fields, components and pricing (not its status). */
final class UpdateBundleRequest extends FormRequest
{
    use BundlePayloadRules;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->bundleRules();
    }
}
