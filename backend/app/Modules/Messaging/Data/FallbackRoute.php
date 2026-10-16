<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Data;

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\Provider;

/**
 * Where the copy of a failed message goes (BR-CU07, D70).
 */
final readonly class FallbackRoute
{
    public function __construct(
        public Channel $channel,
        public Provider $provider,
    ) {}
}
