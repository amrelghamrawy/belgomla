<?php

declare(strict_types=1);

use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Data\ReportOptionsData;
use App\Modules\Reports\Enums\CustomerView;
use App\Modules\Reports\Enums\ProductSort;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use Carbon\CarbonImmutable;

/*
| V2 Task 16: the filters and options of the advanced reports, read from a validated request.
*/

/**
 * @param  array<string, mixed>  $input
 */
function validatedReportRequest(array $input): ReportFiltersRequest
{
    $request = ReportFiltersRequest::create('/', 'GET', $input);
    $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    return $request;
}

test('the report filters take the range, grouping, category and customer group they are given', function (): void {
    $request = validatedReportRequest(['from' => '2026-09-01', 'to' => '2026-09-30', 'group' => 'month', 'category_id' => '7', 'customer_group_id' => 3]);

    expect(ReportFiltersData::fromRequest($request))->toEqual(new ReportFiltersData('2026-09-01', '2026-09-30', SalesGrouping::Month, 7, 3, 'Africa/Cairo'));
});

test('D60 the report filters default to the last 30 business days by day, without category or group', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 22:30:00', 'UTC'));

    expect(ReportFiltersData::fromRequest(validatedReportRequest([])))->toEqual(new ReportFiltersData('2026-09-12', '2026-10-11', SalesGrouping::Day, null, null, 'Africa/Cairo'));
});

test('the report options take the order, view and thresholds they are given', function (): void {
    $request = validatedReportRequest(['sort' => 'return_rate', 'view' => 'dormant', 'dormant_days' => '30', 'dead_days' => 90, 'dead_only' => '1']);

    expect(ReportOptionsData::fromRequest($request))->toEqual(new ReportOptionsData(ProductSort::ReturnRate, CustomerView::Dormant, 30, 90, true));
});

test('the report options default to revenue, top customers, 45 and 60 days and every sku', function (): void {
    expect(ReportOptionsData::fromRequest(validatedReportRequest([])))->toEqual(new ReportOptionsData(ProductSort::Revenue, CustomerView::Top, 45, 60, false));
});

test('the report page defaults to the first page of 20 and the file to excel', function (): void {
    $request = validatedReportRequest([]);

    expect($request->page())->toBe(1)
        ->and($request->perPage())->toBe(20)
        ->and($request->fileFormat())->toBe(App\Modules\Catalog\Enums\SpreadsheetFormat::Xlsx);
});
