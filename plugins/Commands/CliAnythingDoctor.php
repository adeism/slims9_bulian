<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:doctor`
 *
 * Read-only capability + health report. Always safe to run, even with
 * allow_write disabled.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingDoctor extends CliAnythingCommand
{
    protected string $signature = 'clianything:doctor {--json}';

    protected string $description = 'Report CLI-Anything capabilities, gates and SLiMS health';

    protected function handle()
    {
        try {
            return $this->respond('doctor', $this->service()->doctor());
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
