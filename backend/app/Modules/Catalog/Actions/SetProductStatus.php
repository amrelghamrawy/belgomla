<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\InvalidProductTransition;
use App\Modules\Catalog\Models\Product;

/**
 * BR-P04 state machine: active ⇄ inactive, coming_soon → active | inactive. Anything else is
 * 409 invalid_transition. Inactivation is audited (BR-L); bulk changes pass their batch id.
 * Joins the caller's transaction when called from BulkSetProductStatus.
 */
final readonly class SetProductStatus
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Product $product, ProductStatus $status, ?string $batchId = null): Product
    {
        $from = $product->status;

        if (! $from->canTransitionTo($status)) {
            throw InvalidProductTransition::from($from, $status);
        }

        $product->update(['status' => $status]);

        $this->audit->log('product.status_changed', $product, ['status' => $from->value], ['status' => $status->value], $batchId);

        return $product;
    }
}
