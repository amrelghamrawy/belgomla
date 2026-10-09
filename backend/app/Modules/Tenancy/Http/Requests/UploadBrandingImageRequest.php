<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class UploadBrandingImageRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,ico,svg', 'max:2048'],
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
}
