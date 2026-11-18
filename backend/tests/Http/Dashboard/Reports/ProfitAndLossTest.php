<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Orders\Models\OrderItem;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->actingAsOwner();
    $this->inTenant(function (): void {
        $book = $this->reportSku('BK-1', price: '100.00');
        $order = $this->reportOrder([[$book, 10, '100.00']], '2026-10-09 10:00:00');
        OrderItem::query()->where('order_id', $order->id)->update(['unit_cost' => '60.0000']);
        Expense::factory()->create(['spent_on' => '2026-10-09', 'amount' => '150.00', 'category' => 'rent']);
    });
});

test('D100 the statement answers its documented shape for the range and the one before', function (): void {
    // 1,000.00 − 600.00 = 400.00 gross; − 0 losses − 150.00 rent = 250.00; 25.00 %
    $response = $this->tenantJson('GET', '/api/dashboard/reports/profit-and-loss?from=2026-10-08&to=2026-10-10')
        ->assertOk()
        ->assertJsonPath('data.from', '2026-10-08')
        ->assertJsonPath('data.to', '2026-10-10')
        ->assertJsonPath('data.current.net_sales', '1000.00')
        ->assertJsonPath('data.current.gross_profit', '400.00')
        ->assertJsonPath('data.current.net_profit', '250.00')
        ->assertJsonPath('data.current.net_margin', '25.00')
        ->assertJsonPath('data.previous.from', '2026-10-05');

    expectDocumentedShape($response->json('data'), 'ProfitAndLossReport');
    expectDocumentedShape($response->json('data.current'), 'ProfitAndLossFigures');
});

test('D100 the statement exports one row per line for both ranges', function (): void {
    $response = $this->tenantJson('GET', '/api/dashboard/reports/profit-and-loss/export?format=csv&from=2026-10-08&to=2026-10-10')
        ->assertOk()
        ->assertHeader('Content-Type', SpreadsheetFormat::Csv->mimeType());

    $rows = array_values(spreadsheetRows(downloadedFile($response, SpreadsheetFormat::Csv), SpreadsheetFormat::Csv));

    expect($rows[1])->toBe(['صافي المبيعات', '1000', '0'])
        ->and($rows[6])->toBe(['صافي الربح', '250', '0'])
        ->and($rows[7])->toBe(['هامش صافي الربح %', '25', '']);
});

test('the statement takes the shared range validation', function (): void {
    $this->tenantJson('GET', '/api/dashboard/reports/profit-and-loss?from=2026-10-10&to=2026-10-01')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});
