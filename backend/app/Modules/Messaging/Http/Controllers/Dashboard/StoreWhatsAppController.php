<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Dashboard;

use App\Modules\Messaging\Actions\CheckWhatsAppPairing;
use App\Modules\Messaging\Actions\DisconnectWhatsApp;
use App\Modules\Messaging\Actions\StartWhatsAppPairing;
use App\Modules\Messaging\Http\Resources\StoreWhatsAppResource;

final readonly class StoreWhatsAppController
{
    /** GET /api/dashboard/settings/whatsapp — D70 */
    public function show(CheckWhatsAppPairing $check): StoreWhatsAppResource
    {
        return new StoreWhatsAppResource($check->handle());
    }

    /** POST /api/dashboard/settings/whatsapp — D70: start linking by QR */
    public function store(StartWhatsAppPairing $start): StoreWhatsAppResource
    {
        return new StoreWhatsAppResource($start->handle());
    }

    /** DELETE /api/dashboard/settings/whatsapp — D70: unlink */
    public function destroy(DisconnectWhatsApp $disconnect): StoreWhatsAppResource
    {
        return new StoreWhatsAppResource($disconnect->handle());
    }
}
