<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — `clianything:biblio:get`
 *
 * Read-only bibliography detail: metadata, authors, subjects and items
 * with their loan status.
 *
 * @license GPLv3
 */

namespace Commands;

use Commands\Support\CliAnythingCommand;

class CliAnythingBiblioGet extends CliAnythingCommand
{
    protected string $signature = 'clianything:biblio:get {id} {--json}';

    protected string $description = 'Show one bibliography with authors, subjects and items';

    protected function handle()
    {
        try {
            return $this->respond('biblio.get', $this->service()->biblioGet((int)$this->argument('id')));
        } catch (\Throwable $error) {
            return $this->fail($error);
        }
    }
}
