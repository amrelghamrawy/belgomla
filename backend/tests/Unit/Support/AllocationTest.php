<?php

declare(strict_types=1);

use App\Support\Allocation;

test('D93 an amount is split in proportion to the weights, half-up, the remainder on the last part', function (string $amount, array $weights, array $shares): void {
    expect(Allocation::proportional($amount, $weights))->toBe($shares);
})->with([
    'even' => ['300.00', ['100.00', '100.00', '100.00'], ['100.00', '100.00', '100.00']],
    'proportional' => ['270.00', ['100.00', '200.00'], ['90.00', '180.00']],
    'thirds round half-up' => ['100.00', ['1.00', '1.00', '1.00'], ['33.33', '33.33', '33.34']],
    'half a piaster rounds up' => ['0.03', ['1.00', '1.00'], ['0.02', '0.01']],
    'one part takes it all' => ['42.50', ['7.00'], ['42.50']],
    'zero weights split evenly' => ['10.00', ['0.00', '0.00'], ['5.00', '5.00']],
]);

test('D93 the shares always add up to the amount', function (string $amount, array $weights): void {
    $shares = Allocation::proportional($amount, $weights);

    expect(array_reduce($shares, fn (string $sum, string $share): string => bcadd($sum, $share, 2), '0.00'))->toBe($amount);
})->with([
    'awkward weights' => ['367.20', ['92.00', '113.50', '101.25', '101.25']],
    'more parts than piasters' => ['0.03', ['1.00', '1.00', '1.00', '1.00', '1.00', '1.00', '1.00']],
]);

test('D93 the last part is never negative when the rounding of the others overshoots', function (): void {
    $shares = Allocation::proportional('0.03', ['1.00', '1.00', '1.00', '1.00', '1.00', '1.00', '1.00']);

    expect(array_filter($shares, fn (string $share): bool => bccomp($share, '0', 2) < 0))->toBe([]);
});

test('nothing to split over is a programming error', function (): void {
    Allocation::proportional('10.00', []);
})->throws(InvalidArgumentException::class);
