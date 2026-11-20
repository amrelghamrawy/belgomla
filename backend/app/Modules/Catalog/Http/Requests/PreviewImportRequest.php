<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * POST /api/dashboard/products/import/preview: multipart `file`, an .xlsx or .csv (.txt is how some browsers name
 * a CSV) up to `catalog.import_max_kb`.
 */
final class PreviewImportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,csv,txt', 'max:'.config()->integer('catalog.import_max_kb')],
        ];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new RuntimeException('Validated upload is missing.');
        }

        return $file;
    }
}
