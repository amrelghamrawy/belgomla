<?php

declare(strict_types=1);

arch('audit logs are only written through the Audit module')
    ->expect(App\Modules\Audit\Models\AuditLog::class)
    ->toOnlyBeUsedIn([
        'App\Modules\Audit',
    ]);

arch('the tenant context is a plain final service')
    ->expect(App\Modules\Tenancy\TenantContext::class)
    ->toBeFinal()
    ->toExtendNothing();
