<?php

declare(strict_types=1);

use App\Modules\Orders\Data\CheckoutProblem;
use App\Modules\Orders\Data\CheckoutReport;
use App\Modules\Orders\Exceptions\CheckoutFailed;

$mov = ['required' => '1500.00', 'current' => '1020.00'];
$credit = ['limit' => '100.00', 'exposure' => '0.00', 'order_total' => '1020.00'];

test('BR-PR06 a clean report passes and hands back its lines', function (): void {
    expect(new CheckoutReport([], [], '0.00', null, null, null)->assertPasses())->toBe([]);
});

test('BR-PR06 problems refuse with every problem listed', function (): void {
    $report = new CheckoutReport([], [7 => CheckoutProblem::belowMoq(7, 5)], '0.00', null, null, null);

    expect(fn (): array => $report->assertPasses())->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => 7, 'reason' => 'below_moq', 'moq' => 5]]);
    })
        ->and($report->problemFor(7)?->reason)->toBe('below_moq')
        ->and($report->problemFor(8))->toBeNull();
});

test('D84 skipping the mov lets a short order through', function () use ($mov): void {
    $report = new CheckoutReport([], [], '1020.00', '1500.00', $mov, null);

    expect(fn (): array => $report->assertPasses())->toThrow(CheckoutFailed::class)
        ->and($report->assertPasses(skipMov: true))->toBe([]);
});

test('D84 skipping the mov never skips the credit limit', function () use ($mov, $credit): void {
    $report = new CheckoutReport([], [], '1020.00', '1500.00', $mov, $credit);

    expect(fn (): array => $report->assertPasses(skipMov: true))->toThrow(function (CheckoutFailed $e) use ($credit): void {
        expect($e->details)->toBe(['credit' => $credit]);
    });
});
