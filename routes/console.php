<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Claims due profiles and queues one refresh each. Safe to overlap or run on several hosts
// (claiming is atomic); withoutOverlapping just avoids pointless concurrent ticks.
Schedule::command('refresh:schedule')->everyMinute()->withoutOverlapping();
