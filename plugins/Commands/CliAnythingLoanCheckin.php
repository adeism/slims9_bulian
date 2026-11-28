<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:loan:checkin`
 *
 * GATED WRITE.
 *
 *   dry run (default) : computes overdue days and the fine SLiMS would
 *                       charge, writes nothing.
 *   --commit          : requires `allow_write`; goes through
 *                       `circulation::returnItem()` so fines, loan_history
 *                       and reservations behave exactly like the GUI.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingLoanCheckin extends CliAnythingCommand
{
    protected string $signature = 'clianything:loan:checkin {--loanid=} {--itemcode=} {--commit} {--json}';

    protected string $description = 'Return an item by loan id or item code (dry run unless --commit)';

    protected function handle()
    {
        try {
            $loanId = (string)$this->option('loanid');
            $itemCode = (string)$this->option('itemcode');

            return $this->respond('loan.checkin', $this->service()->loanCheckin(
                $loanId === '' ? null : (int)$loanId,
                $itemCode === '' ? null : $itemCode,
                $this->flag('commit')
            ));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
