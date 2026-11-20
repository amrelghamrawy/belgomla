<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\URL;

/**
 * GET /api/dashboard/products/export/{export}: the temporary signed link of a queued export. A link with a wrong or
 * expired signature is answered like an unknown export (404), not 403, so the permission check keeps its meaning.
 */
final class DownloadExportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function validSignature(): bool
    {
        return URL::hasValidSignature($this, absolute: false);
    }
}
