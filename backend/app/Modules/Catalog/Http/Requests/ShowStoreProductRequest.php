<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Concerns\ResolvesCustomer;
use Illuminate\Foundation\Http\FormRequest;

/** GET /api/store/products/{slug}: no input, but price visibility depends on the customer. */
final class ShowStoreProductRequest extends FormRequest
{
    use ResolvesCustomer;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
