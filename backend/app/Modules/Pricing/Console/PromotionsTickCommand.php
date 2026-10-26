<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Console;

use App\Modules\Pricing\Actions\AdvancePromotions;
use Illuminate\Console\Command;

/**
 * `pricing:promotions-tick` (BR-PR15, V2 Task 12, D90 c): every minute, moves the promotions of every store that started
 * to `active` and those that are over to `ended`. Prices follow the times on their own; no price is cached, so there is
 * nothing else to refresh.
 */
final class PromotionsTickCommand extends Command
{
    protected $signature = 'pricing:promotions-tick';

    protected $description = 'Move started promotions to active and finished ones to ended';

    public function handle(AdvancePromotions $advance): int
    {
        $moved = $advance->handle();

        $this->info("activated {$moved['activated']}, ended {$moved['ended']}");

        return self::SUCCESS;
    }
}
