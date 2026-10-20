<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ReadsProductFilter;
use App\Modules\Catalog\Concerns\ResolvesCustomer;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/store/price-list/export: the price list's selection (`category` slug, `f[key]`, `q`) and `format=xlsx`,
 * the only price list file (D83).
 */
final class ExportPriceListRequest extends FormRequest
{
    use ReadsProductFilter;
    use ResolvesCustomer;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'status' => ['prohibited'],
            'format' => ['sometimes', 'in:'.SpreadsheetFormat::Xlsx->value],
        ];
    }

    protected function categoryBySlug(): bool
    {
        return true;
    }
}
