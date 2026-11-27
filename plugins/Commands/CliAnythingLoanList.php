<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:loan:list`
 *
 * Read-only circulation listing (active, overdue, returned, all).
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingLoanList extends CliAnythingCommand
{
    protected string $signature = 'clianything:loan:list {--status=active} {--member=} {--limit=20} {--json}';

    protected string $description = 'List loans by status (active|overdue|returned|all)';

    protected function handle()
    {
        try {
            return $this->respond('loan.list', $this->service()->loanList(
                (string)$this->option('status'),
                (string)$this->option('member'),
                (int)$this->option('limit')
            ));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
