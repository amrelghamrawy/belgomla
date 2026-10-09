<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Support\Http\PaginatedRequest;

/** `?page=&per_page=` of the paginated reports: outstanding per customer and low-stock SKUs (08-api-contract.md §11). */
final class ReportPageRequest extends PaginatedRequest {}
