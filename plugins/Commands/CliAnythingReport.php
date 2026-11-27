<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:report`
 *
 * Read-only operational summary for dashboards and chatbots.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingReport extends CliAnythingCommand
{
    protected string $signature = 'clianything:report {--json}';

    protected string $description = 'Collection, membership, circulation and fines summary';

    protected function handle()
    {
        try {
            return $this->respond('report.summary', $this->service()->reportSummary());
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
