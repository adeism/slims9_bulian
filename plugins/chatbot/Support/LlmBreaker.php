<?php
/**
 * Circuit breaker for the optional model connection
 *
 * A model server that is down must not add a timeout to every unanswered
 * question. After a few consecutive failures the breaker opens for a cooldown
 * and the engine answers from its own text without touching the network.
 *
 * The state is one small guarded file (flock, atomic rename) next to the
 * conversation log, so it survives between requests without a table.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class LlmBreaker
{
    const NAME = 'llm-state.php';

    /** @var array */
    private $config;

    /** @var string */
    private $path;

    /**
     * @param string $dir    directory for the state file
     * @param array  $config
     */
    public function __construct(string $dir, array $config = [])
    {
        $this->config = $config;
        $this->path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . self::NAME;
    }

    /**
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array{failures:int,until:int,last_error:string,last_at:int}
     */
    public function state(): array
    {
        $default = ['failures' => 0, 'until' => 0, 'last_error' => '', 'last_at' => 0];

        if (!is_file($this->path)) {
            return $default;
        }

        try {
            $data = include $this->path;
        } catch (\Throwable $error) {
            $data = null;
        }

        if (!is_array($data)) {
            return $default;
        }

        return [
            'failures' => max(0, (int)($data['failures'] ?? 0)),
            'until' => max(0, (int)($data['until'] ?? 0)),
            'last_error' => (string)($data['last_error'] ?? ''),
            'last_at' => max(0, (int)($data['last_at'] ?? 0)),
        ];
    }

    /**
     * Is the cooldown still running?
     *
     * @param int|null $now
     * @return bool
     */
    public function blocked(?int $now = null): bool
    {
        $now = is_null($now) ? time() : $now;

        return (int)$this->state()['until'] > $now;
    }

    /**
     * @param string   $code
     * @param int|null $now
     * @return void
     */
    public function fail(string $code, ?int $now = null): void
    {
        $now = is_null($now) ? time() : $now;
        $state = $this->state();
        $limit = max(1, (int)($this->config['llm']['circuit_failures'] ?? 3));
        $cooldown = max(30, (int)($this->config['llm']['circuit_cooldown'] ?? 300));

        $state['failures']++;
        $state['last_error'] = Str::slice($code, 0, 40);
        $state['last_at'] = $now;

        if ($state['failures'] >= $limit) {
            $state['until'] = $now + $cooldown;
        }

        $this->write($state);
    }

    /**
     * @return void
     */
    public function succeed(): void
    {
        $state = $this->state();

        if ($state['failures'] === 0 && $state['until'] === 0) {
            return;
        }

        $this->write(['failures' => 0, 'until' => 0, 'last_error' => '', 'last_at' => time()]);
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->write(['failures' => 0, 'until' => 0, 'last_error' => '', 'last_at' => 0]);
    }

    /**
     * @param array $state
     * @return void
     */
    private function write(array $state): void
    {
        $dir = dirname($this->path);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $body = "<?php\n/**\n * Chatbot model circuit breaker. Safe to delete: it resets itself.\n */\n\nreturn "
            . var_export([
                'failures' => (int)$state['failures'],
                'until' => (int)$state['until'],
                'last_error' => (string)$state['last_error'],
                'last_at' => (int)$state['last_at'],
            ], true) . ";\n";

        $temp = $this->path . '.tmp-' . substr(sha1(uniqid('', true)), 0, 8);
        $handle = @fopen($temp, 'wb');

        if (!is_resource($handle)) {
            return;
        }

        $written = @fwrite($handle, $body);
        @fflush($handle);
        @fclose($handle);

        if ($written !== strlen($body)) {
            @unlink($temp);

            return;
        }

        @chmod($temp, 0640);
        @rename($temp, $this->path);
    }
}
