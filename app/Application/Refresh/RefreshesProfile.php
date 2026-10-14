<?php

declare(strict_types=1);

namespace App\Application\Refresh;

/**
 * Driving port: what a queue job calls. Two implementations exist on purpose:
 * the legacy handler (kept to reproduce the incident) and the fixed use case.
 */
interface RefreshesProfile
{
    public function execute(RefreshCommand $command): RefreshOutcome;
}
