<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:member:get`
 *
 * Read-only patron lookup. PII is masked unless `--reveal` is used.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingMemberGet extends CliAnythingCommand
{
    protected string $signature = 'clianything:member:get {id} {--reveal} {--json}';

    protected string $description = 'Show one member with loan summary (PII masked unless --reveal)';

    protected function handle()
    {
        try {
            return $this->respond('member.get', $this->service()->memberGet(
                (string)$this->argument('id'),
                !$this->flag('reveal')
            ));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
