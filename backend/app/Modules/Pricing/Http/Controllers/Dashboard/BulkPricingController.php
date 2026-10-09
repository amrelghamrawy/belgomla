<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Dashboard;

use App\Modules\Pricing\Actions\BulkApplyPricing;
use App\Modules\Pricing\Data\BulkPricingData;
use App\Modules\Pricing\Http\Requests\BulkPricingRequest;
use App\Modules\Pricing\Http\Resources\BulkPricingResultResource;

final readonly class BulkPricingController
{
    /** POST /api/dashboard/pricing/bulk — BR-PR11 */
    public function store(BulkPricingRequest $request, BulkApplyPricing $bulk): BulkPricingResultResource
    {
        return new BulkPricingResultResource($bulk->handle(BulkPricingData::fromRequest($request)));
    }
}
