<?php

namespace App\Console\Commands;

use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand as CollisionTestCommand;

/**
 * `php artisan test` with the project's coverage standard built in.
 *
 * Collision's command only prints coverage unless --min is given. Here, whenever --coverage is used
 * without an explicit --min, the run fails (exit code 1) below MINIMUM_COVERAGE percent line coverage.
 * Opt out on purpose with `--min=0`, e.g. to look at the coverage of a single filtered test.
 */
class TestCommand extends CollisionTestCommand
{
    /** Minimum line coverage, in percent, required on every coverage run. */
    public const MINIMUM_COVERAGE = 90;

    public function handle()
    {
        $this->applyMinimumCoverage();

        return parent::handle();
    }

    protected function applyMinimumCoverage(): void
    {
        if ($this->option('coverage') && $this->option('min') === null) {
            $this->input->setOption('min', (string) self::MINIMUM_COVERAGE);
        }
    }
}
