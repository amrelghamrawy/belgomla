<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductRelation;

/** BR-P10: removes a relation the product declared. */
final readonly class RemoveProductRelation
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ProductRelation $relation): void
    {
        $relation->delete();

        $this->audit->log('product_relation.removed', $relation, [
            'product_id' => $relation->product_id, 'related_product_id' => $relation->related_product_id, 'type' => $relation->type->value,
        ]);
    }
}
