<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:biblio:list`
 *
 * Read-only bibliography search (title, ISBN/ISSN, call number, author).
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingBiblioList extends CliAnythingCommand
{
    protected string $signature = 'clianything:biblio:list {--q=} {--limit=20} {--offset=0} {--json}';

    protected string $description = 'Search bibliography records';

    protected function handle()
    {
        try {
            return $this->respond('biblio.list', $this->service()->biblioList(
                (string)$this->option('q'),
                (int)$this->option('limit'),
                (int)$this->option('offset')
            ));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
