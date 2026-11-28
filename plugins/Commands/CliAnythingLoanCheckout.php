<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:loan:checkout`
 *
 * GATED WRITE.
 *
 *   dry run (default) : runs SLiMS' own loan rules and reports the due
 *                       dates that would be written, touching nothing.
 *   --commit          : requires `allow_write`; more than one item also
 *                       requires `allow_bulk` and triggers a DB backup.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;
use Commands\Support\CliAnythingException;

class CliAnythingLoanCheckout extends CliAnythingCommand
{
    protected string $signature = 'clianything:loan:checkout {--member=} {--item=} {--items=} {--commit} {--json}';

    protected string $description = 'Check out one or more items for a member (dry run unless --commit)';

    protected function handle()
    {
        try {
            $items = [];

            $single = (string)$this->option('item');
            if ($single !== '') {
                $items[] = $single;
            }

            $multiple = (string)$this->option('items');
            if ($multiple !== '') {
                foreach (explode(',', $multiple) as $code) {
                    $items[] = $code;
                }
            }

            if (count($items) === 0) {
                throw new CliAnythingException('INVALID_ARGUMENT', 'Provide --item=CODE or --items=CODE1,CODE2');
            }

            return $this->respond('loan.checkout', $this->service()->loanCheckout(
                (string)$this->option('member'),
                $items,
                $this->flag('commit')
            ));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
