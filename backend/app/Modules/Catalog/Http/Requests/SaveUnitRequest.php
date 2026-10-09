<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Create and rename take the same single field, so one request serves POST and PUT /units. */
final class SaveUnitRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
        ];
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }
}
