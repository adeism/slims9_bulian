<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:loan:extend`
 *
 * GATED WRITE.
 *
 *   dry run (default) : projects the new due date, checks the reborrow
 *                       limit and reservations, writes nothing.
 *   --commit          : requires `allow_write`; uses
 *                       `circulation::extendItemLoan()`.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;
use Commands\Support\CliAnythingException;

class CliAnythingLoanExtend extends CliAnythingCommand
{
    protected string $signature = 'clianything:loan:extend {--loanid=} {--commit} {--json}';

    protected string $description = 'Renew a loan (dry run unless --commit)';

    protected function handle()
    {
        try {
            $loanId = (int)$this->option('loanid');
            if ($loanId <= 0) {
                throw new CliAnythingException('INVALID_ARGUMENT', 'Provide --loanid=ID');
            }

            return $this->respond('loan.extend', $this->service()->loanExtend($loanId, $this->flag('commit')));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
