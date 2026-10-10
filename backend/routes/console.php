<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Run every minute in production by belgomla-scheduler.timer (deploy/systemd). The health monitor is not here:
| deploy/monitor.sh runs `platform:monitor` with the external /up results, so open problems are judged in one place.
*/

Schedule::command('queue:prune-failed --hours=720')->daily();
