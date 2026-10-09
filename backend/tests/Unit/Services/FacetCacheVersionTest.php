<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\FacetCacheVersion;

test('the version starts at zero and increments per bump', function (): void {
    $versions = app(FacetCacheVersion::class);

    expect($versions->current(42))->toBe(0);

    $versions->bump(42);
    $versions->bump(42);

    expect($versions->current(42))->toBe(2);
});

test('versions are independent per tenant', function (): void {
    $versions = app(FacetCacheVersion::class);

    $versions->bump(1);

    expect($versions->current(1))->toBe(1)
        ->and($versions->current(2))->toBe(0);
});
