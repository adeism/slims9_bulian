<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:item:get`
 *
 * Read-only copy lookup by item code (barcode).
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingItemGet extends CliAnythingCommand
{
    protected string $signature = 'clianything:item:get {code} {--json}';

    protected string $description = 'Show one item (copy) by item code';

    protected function handle()
    {
        try {
            return $this->respond('item.get', $this->service()->itemGet((string)$this->argument('code')));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
