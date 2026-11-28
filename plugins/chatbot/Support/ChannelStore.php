<?php
/**
 * File-backed state for messaging channels
 *
 * A WhatsApp/Telegram webhook has no browser cookie, so the browser's
 * session-bound confirmation token cannot be used. The channel instead
 * keeps one small state file per sender:
 *
 *   - the pending action (so the next "YA" can be executed),
 *   - two rate-limit buckets.
 *
 * Each file is written atomically under an exclusive lock, pruned on every
 * touch, and starts with `<?php exit; ?>` so that even if the storage
 * directory is reachable over HTTP the browser gets an empty response
 * instead of the JSON payload.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class ChannelStore
{
    /** Prefix that makes a direct HTTP hit a no-op. */
    const GUARD = "<?php exit; ?>\n";

    /** @var string */
    private $dir;

    /**
     * @param array $config channel configuration block
     */
    public function __construct(array $config = [])
    {
        $this->dir = self::resolveDir($config);
    }

    /**
     * @return string
     */
    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * @param string $key
     * @return array
     */
    public function read(string $key): array
    {
        $state = $this->decode($this->readFile($this->path($key)));

        return $this->prune($state, time());
    }

    /**
     * Count one hit in a bucket. False when the bucket is exhausted.
     *
     * @param string   $key
     * @param string   $bucket
     * @param int      $limit
     * @param int      $window seconds
     * @param int|null $now
     * @return bool
     */
    public function allow(string $key, string $bucket, int $limit, int $window, ?int $now = null): bool
    {
        $now = is_null($now) ? time() : $now;
        $limit = max(1, $limit);
        $window = max(1, $window);

        return (bool)$this->mutate($key, function (array $state) use ($bucket, $limit, $window, $now) {
            $entry = $state['buckets'][$bucket] ?? ['start' => $now, 'count' => 0];

            if (($now - (int)$entry['start']) >= $window) {
                $entry = ['start' => $now, 'count' => 0];
            }

            if ((int)$entry['count'] >= $limit) {
                $state['buckets'][$bucket] = $entry;

                return ['state' => $state, 'result' => false];
            }

            $entry['count'] = (int)$entry['count'] + 1;
            $state['buckets'][$bucket] = $entry;

            return ['state' => $state, 'result' => true];
        }, $now);
    }

    /**
     * @param string   $key
     * @param array    $action
     * @param int      $ttl
     * @param int|null $now
     * @return void
     */
    public function putAction(string $key, array $action, int $ttl, ?int $now = null)
    {
        $now = is_null($now) ? time() : $now;

        $this->mutate($key, function (array $state) use ($action, $ttl, $now) {
            $state['action'] = $action;
            $state['action_exp'] = $now + max(30, $ttl);

            return ['state' => $state, 'result' => true];
        }, $now);
    }

    /**
     * Consume the pending action. Null when there is none or it expired.
     *
     * @param string   $key
     * @param int|null $now
     * @return array|null
     */
    public function takeAction(string $key, ?int $now = null)
    {
        $now = is_null($now) ? time() : $now;

        $result = $this->mutate($key, function (array $state) use ($now) {
            $expired = !isset($state['action']) || (int)($state['action_exp'] ?? 0) < $now;
            $action = $expired ? null : (array)$state['action'];

            unset($state['action'], $state['action_exp']);

            return ['state' => $state, 'result' => $action];
        }, $now);

        return is_array($result) ? $result : null;
    }

    /**
     * @param string $key
     * @return void
     */
    public function forget(string $key)
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Remove every state file (tests, logout of a whole channel).
     *
     * @return int number of removed files
     */
    public function reset(): int
    {
        $removed = 0;

        foreach ((array)glob($this->dir . '*.php') as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    /**
     * @param string $key
     * @return string
     */
    private function path(string $key): string
    {
        return $this->dir . sha1($key) . '.php';
    }

    /**
     * @param string        $key
     * @param callable      $callback receives state, returns ['state'=>array,'result'=>mixed]
     * @param int           $now
     * @return mixed
     */
    private function mutate(string $key, callable $callback, int $now)
    {
        $this->ensureDir();
        $path = $this->path($key);
        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open the chatbot channel store at ' . $path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock the chatbot channel store at ' . $path);
            }

            $state = $this->prune($this->decode((string)stream_get_contents($handle)), $now);
            $outcome = $callback($state);
            $state = isset($outcome['state']) && is_array($outcome['state']) ? $outcome['state'] : $state;

            $payload = self::GUARD . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $payload);
            fflush($handle);
            flock($handle, LOCK_UN);

            return $outcome['result'] ?? null;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Drop expired actions and empty buckets.
     *
     * @param array $state
     * @param int   $now
     * @return array
     */
    private function prune(array $state, int $now): array
    {
        if (isset($state['action_exp']) && (int)$state['action_exp'] < $now) {
            unset($state['action'], $state['action_exp']);
        }

        return $state;
    }

    /**
     * @param string $content
     * @return array
     */
    private function decode(string $content): array
    {
        if (strpos($content, self::GUARD) === 0) {
            $content = substr($content, strlen(self::GUARD));
        }

        $state = json_decode(trim($content), true);

        return is_array($state) ? $state : [];
    }

    /**
     * @param string $path
     * @return string
     */
    private function readFile(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $content = @file_get_contents($path);

        return $content === false ? '' : $content;
    }

    /**
     * @return void
     */
    private function ensureDir()
    {
        if (is_dir($this->dir)) {
            return;
        }

        if (!@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create the chatbot channel store at ' . $this->dir);
        }

        @file_put_contents($this->dir . 'index.html', '');
        @file_put_contents(
            $this->dir . '.htaccess',
            "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
        );
    }

    /**
     * @param array $config
     * @return string
     */
    private static function resolveDir(array $config): string
    {
        $configured = trim((string)($config['storage_dir'] ?? ''));

        if ($configured !== '') {
            return rtrim($configured, '/\\') . DIRECTORY_SEPARATOR;
        }

        if (defined('SB')) {
            return SB . 'files' . DIRECTORY_SEPARATOR . 'chatbot' . DIRECTORY_SEPARATOR;
        }

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'slims-chatbot' . DIRECTORY_SEPARATOR;
    }
}
