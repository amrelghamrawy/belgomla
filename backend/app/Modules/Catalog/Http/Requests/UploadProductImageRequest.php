<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/** D26: png/jpg/webp up to 2 MB. */
final class UploadProductImageRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'is_main' => ['sometimes', 'boolean'],
        ];
    }

    public function image(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new RuntimeException('Validated upload is missing.');
        }

        return $file;
    }

    public function isMain(): bool
    {
        return $this->boolean('is_main');
    }
}
