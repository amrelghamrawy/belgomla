<?php

declare(strict_types=1);

use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use Carbon\CarbonImmutable;

/*
| BR-PR15 (V2 Task 12, D90 c): `pricing:promotions-tick` moves promotions across their boundaries and prints the counts.
*/

test('BR-PR15 the tick activates started promotions and ends finished ones', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00'));
    $started = $this->inTenant(fn (): Promotion => Promotion::factory()->create(['starts_at' => '2026-10-27 09:00:00', 'ends_at' => '2026-11-01 00:00:00']));
    $over = $this->inTenant(fn (): Promotion => Promotion::factory()->active()->create(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-26 00:00:00']));

    $this->artisan('pricing:promotions-tick')
        ->expectsOutputToContain('activated 1, ended 1')
        ->assertSuccessful();

    expect($this->inTenant(fn (): array => [$started->refresh()->status, $over->refresh()->status]))->toBe([PromotionStatus::Active, PromotionStatus::Ended]);
});

test('BR-PR15 the tick with nothing to move succeeds quietly', function (): void {
    $this->artisan('pricing:promotions-tick')
        ->expectsOutputToContain('activated 0, ended 0')
        ->assertSuccessful();
});
