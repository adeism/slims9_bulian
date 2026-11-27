<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — base console command
 *
 * Every `clianything:*` tarsius command extends this class so that the
 * payload shape stays identical to the HTTP bridge:
 *
 *   {"ok":true,"schema":"1.0","command":"biblio.list","data":{...},"meta":{...}}
 *   {"ok":false,"error":{"code":"...","message":"...","context":{...}}}
 *
 * @license GPLv3
 */

namespace Commands\Support;

abstract class CliAnythingCommand extends \SLiMS\Cli\Command
{
    /**
     * Is `--json` present? (Symfony VALUE_OPTIONAL flags are not
     * distinguishable from "not provided", so we look at the raw argv.)
     *
     * @return bool
     */
    protected function wantsJson(): bool
    {
        return $this->input->hasParameterOption('--json');
    }

    /**
     * Detect a boolean flag such as `--commit` or `--reveal`.
     *
     * @param string $name
     * @return bool
     */
    protected function flag(string $name): bool
    {
        return $this->input->hasParameterOption('--' . $name);
    }

    /**
     * @return CliAnything
     */
    protected function service(): CliAnything
    {
        return CliAnything::instance();
    }

    /**
     * Emit a success envelope.
     *
     * @param string $command
     * @param array  $data
     * @param array  $meta
     * @return int exit code
     */
    protected function respond(string $command, array $data, array $meta = []): int
    {
        $payload = CliAnything::success($command, $data, $meta);

        if ($this->wantsJson()) {
            $this->output(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->render($command, $data);

        return 0;
    }

    /**
     * Emit a failure envelope (and a non-zero exit code).
     *
     * @param \Throwable $error
     * @return int exit code
     */
    protected function fail(\Throwable $error): int
    {
        if ($error instanceof CliAnythingException) {
            $payload = $error->toArray();
            $exitCode = 2;
        } else {
            $payload = [
                'ok' => false,
                'schema' => CliAnything::SCHEMA_VERSION,
                'error' => [
                    'code' => 'UNEXPECTED_ERROR',
                    'message' => $error->getMessage(),
                    'context' => [],
                ],
            ];
            $exitCode = 1;
        }

        if ($this->wantsJson()) {
            $this->output(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error(sprintf('[%s] %s', $payload['error']['code'], $payload['error']['message']));
        }

        return $exitCode;
    }

    /**
     * Minimal, dependency free human renderer.
     *
     * @param string $command
     * @param array  $data
     * @return void
     */
    protected function render(string $command, array $data)
    {
        if (!empty($data['dry_run'])) {
            $this->output('<comment>DRY RUN — nothing written. Re-run with --commit to execute.</comment>');
            $this->newLine();
        }

        if (!empty($data['committed'])) {
            $this->output('<info>COMMITTED — written to the database and recorded in system_log.</info>');
            if (!empty($data['backup'])) {
                $this->justify(['Backup', $data['backup']]);
            }
            $this->newLine();
        }

        $this->renderNode($data);

        $this->newLine();
    }

    /**
     * @param mixed $node
     * @param int   $depth
     * @return void
     */
    protected function renderNode($node, int $depth = 0)
    {
        if (!is_array($node) || empty($node)) {
            return;
        }

        $indent = str_repeat('  ', $depth);

        foreach ($node as $key => $value) {
            if (is_array($value) && $this->isList($value)) {
                if (count($value) === 0) {
                    continue;
                }
                $this->output($indent . '<fg=cyan>' . $key . '</> (' . count($value) . ')');
                if (is_array(reset($value))) {
                    $this->renderTable($value, $indent . '  ');
                } else {
                    foreach ($value as $entry) {
                        $this->output($indent . '  - ' . $this->stringify($entry));
                    }
                }
            } elseif (is_array($value)) {
                $this->output($indent . '<fg=cyan>' . $key . '</>');
                $this->renderNode($value, $depth + 1);
            } else {
                $this->justify([$indent . $key, $this->stringify($value)]);
            }
        }
    }

    /**
     * @param array  $rows
     * @param string $indent
     * @return void
     */
    protected function renderTable(array $rows, string $indent = '')
    {
        $first = reset($rows);
        if (!is_array($first)) {
            return;
        }

        $columns = array_slice(array_keys($first), 0, 8);
        $header = array_map(function ($column) {
            return strtoupper(str_replace('_', ' ', $column));
        }, $columns);

        $body = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                if (is_bool($value)) {
                    $value = $value ? 'yes' : 'no';
                } elseif (is_array($value)) {
                    $value = json_encode($value);
                }
                $line[] = $this->truncate((string)$value, 40);
            }
            $body[] = $line;
        }

        $this->table($header, $body);
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function stringify($value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_null($value)) {
            return '-';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string)$value;
    }

    protected function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1) . '…';
    }

    protected function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
