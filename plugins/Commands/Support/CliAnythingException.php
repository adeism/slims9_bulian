<?php
/**
 * CLI-Anything for SLiMS 9 Bulian
 *
 * Machine readable error object shared by the tarsius commands
 * (`plugins/Commands/CliAnything*.php`) and the HTTP bridge
 * (`plugins/cli_anything/cli_anything.plugin.php`).
 *
 * @license GPLv3
 */

namespace Commands\Support;

class CliAnythingException extends \Exception
{
    /**
     * Stable error code consumed by the Python harness.
     *
     * @var string
     */
    protected string $errorCode = 'GENERAL_ERROR';

    /**
     * Extra payload describing the failure.
     *
     * @var array
     */
    protected array $context = [];

    public function __construct(string $errorCode, string $message, array $context = [])
    {
        $this->errorCode = $errorCode;
        $this->context = $context;

        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }

    /**
     * Envelope used by every CLI-Anything entry point.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'ok' => false,
            'schema' => CliAnything::SCHEMA_VERSION,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'context' => $this->context,
            ],
        ];
    }
}
