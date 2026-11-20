<?php

declare(strict_types=1);

use App\Modules\Platform\Data\VerticalData;
use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use Illuminate\Http\Request;

test('D108 the tenant summary carries its vertical as key and name', function (): void {
    $vertical = new VerticalData(7, 'libraries', 'مكتبات', VerticalStatus::Active);

    expect(new TenantResource($this->tenant, $vertical)->resolve(new Request))->toBe([
        'name' => 'مكتبة النور',
        'subdomain' => 'alnoor',
        'status' => 'active',
        'vertical' => ['key' => 'libraries', 'name' => 'مكتبات'],
    ]);
});
