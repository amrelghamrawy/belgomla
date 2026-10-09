<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/dashboard/reports/overview (08-api-contract.md §11): no input; "today" is a business day (D60). */
final class OverviewReportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function timezone(): string
    {
        return config()->string('app.business_timezone');
    }
}
