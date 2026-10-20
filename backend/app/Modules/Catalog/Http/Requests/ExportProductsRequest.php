<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ReadsProductFilter;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/dashboard/products/export: the product list's filter (`q, category (id), status, f[key]`) and
 * `format=xlsx|csv` (default xlsx). GET /api/dashboard/products/import/template takes the format only.
 */
final class ExportProductsRequest extends FormRequest
{
    use ReadsProductFilter;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'format' => ['sometimes', Rule::enum(SpreadsheetFormat::class)],
        ];
    }

    public function fileFormat(): SpreadsheetFormat
    {
        return $this->enum('format', SpreadsheetFormat::class) ?? SpreadsheetFormat::Xlsx;
    }
}
