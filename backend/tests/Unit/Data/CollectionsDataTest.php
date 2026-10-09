<?php

declare(strict_types=1);

use App\Modules\Audit\Data\AuditLogFiltersData;
use App\Modules\Audit\Http\Requests\ListAuditLogsRequest;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Data\CollectionFiltersData;
use App\Modules\Collections\Http\Requests\ListCollectionsRequest;
use App\Modules\Collections\Http\Requests\RecordCollectionRequest;
use App\Modules\Collections\Http\Requests\VoidCollectionRequest;
use App\Modules\Reports\Data\SalesReportData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Http\Requests\SalesReportRequest;
use Carbon\CarbonImmutable;

/**
 * @template T of Illuminate\Foundation\Http\FormRequest
 *
 * @param  class-string<T>  $class
 * @return T
 */
function validatedCollectionsRequest(string $class, array $input, string $method = 'POST'): Illuminate\Foundation\Http\FormRequest
{
    $request = $class::create('/', $method, $input);
    $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    return $request;
}

test('BR-C02 collection data normalises the amount, takes the time in utc and trims the note', function (): void {
    $request = validatedCollectionsRequest(RecordCollectionRequest::class, ['amount' => '150.5', 'collected_at' => '2026-10-01T12:00:00+03:00', 'note' => '  دفعة  ']);

    expect(CollectionData::fromRequest($request))->toEqual(new CollectionData('150.50', CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'), 'دفعة'));
});

test('BR-C02 collection data without a time or a note leaves both null', function (array $input): void {
    $request = validatedCollectionsRequest(RecordCollectionRequest::class, ['amount' => 90, ...$input]);

    expect(CollectionData::fromRequest($request))->toEqual(new CollectionData('90.00'));
})->with(['not sent' => [[]], 'blank note' => [['note' => '   ']], 'null note' => [['note' => null]]]);

test('BR-C02 the void reason is trimmed', function (): void {
    expect(validatedCollectionsRequest(VoidCollectionRequest::class, ['reason' => '  مكرر '])->reason())->toBe('مكرر');
});

test('D60 collection filters turn business days into utc bounds', function (): void {
    $request = validatedCollectionsRequest(ListCollectionsRequest::class, ['from' => '2026-10-01', 'to' => '2026-10-09', 'customer_id' => '4', 'order' => '1024'], 'GET');

    expect(CollectionFiltersData::fromRequest($request))->toEqual(new CollectionFiltersData(
        CarbonImmutable::parse('2026-09-30 21:00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-09 20:59:59.999999', 'UTC'),
        4,
        1024,
    ));
});

test('collection filters left out are null', function (): void {
    expect(CollectionFiltersData::fromRequest(validatedCollectionsRequest(ListCollectionsRequest::class, [], 'GET')))->toEqual(new CollectionFiltersData());
});

test('D5 the sales report takes the range and grouping it is given', function (): void {
    $request = validatedCollectionsRequest(SalesReportRequest::class, ['from' => '2026-09-01', 'to' => '2026-09-30', 'group' => 'week'], 'GET');

    expect(SalesReportData::fromRequest($request))->toEqual(new SalesReportData('2026-09-01', '2026-09-30', SalesGrouping::Week, 'Africa/Cairo'));
});

test('D5 the sales report defaults to the last 30 business days by day', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 22:30:00', 'UTC'));

    expect(SalesReportData::fromRequest(validatedCollectionsRequest(SalesReportRequest::class, [], 'GET')))
        ->toEqual(new SalesReportData('2026-09-12', '2026-10-11', SalesGrouping::Day, 'Africa/Cairo'));
});

test('audit filters: entity, actor, business days and a lower-cased batch id', function (): void {
    $request = validatedCollectionsRequest(ListAuditLogsRequest::class, [
        'entity_type' => 'orders', 'entity_id' => '7', 'actor_id' => '3', 'from' => '2026-10-01', 'to' => '2026-10-01', 'batch_id' => '9F1B2C3D-0000-4000-8000-000000000001',
    ], 'GET');

    expect(AuditLogFiltersData::fromRequest($request))->toEqual(new AuditLogFiltersData(
        'orders',
        7,
        3,
        CarbonImmutable::parse('2026-09-30 21:00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-01 20:59:59.999999', 'UTC'),
        '9f1b2c3d-0000-4000-8000-000000000001',
    ));
});

test('audit filters left out are null', function (): void {
    expect(AuditLogFiltersData::fromRequest(validatedCollectionsRequest(ListAuditLogsRequest::class, [], 'GET')))->toEqual(new AuditLogFiltersData());
});
