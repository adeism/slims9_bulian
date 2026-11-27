<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:biblio:list`
 *
 * Read-only bibliography search (title, ISBN/ISSN, call number, author,
 * subject). Tokens are ranked by field, typos fall through to a shortened
 * token and then to a "did you mean" list; `--sort` chooses relevance
 * (default), popular, newest or title.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingBiblioList extends CliAnythingCommand
{
    protected string $signature = 'clianything:biblio:list {--q=} {--limit=20} {--offset=0} {--sort=relevance} {--json}';

    protected string $description = 'Search bibliography records';

    protected function handle()
    {
        try {
            return $this->respond('biblio.list', $this->service()->biblioList(
                (string)$this->option('q'),
                (int)$this->option('limit'),
                (int)$this->option('offset'),
                (string)$this->option('sort')
            ));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
