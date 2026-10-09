<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Requests;

use App\Support\Http\PaginatedRequest;

/** GET /api/dashboard/customers/{id}/statement: `?page=&per_page=` over the statement's orders (08-api-contract.md §10). */
final class CustomerStatementRequest extends PaginatedRequest {}
