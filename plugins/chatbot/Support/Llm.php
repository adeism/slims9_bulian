<?php
/**
 * Connection to a local model or an OpenAI-compatible API
 *
 * One shape covers Ollama (`/v1`), LM Studio, llama.cpp server, vLLM,
 * LocalAI, OpenRouter and OpenAI itself: `POST {base}/chat/completions` and
 * `GET {base}/models`.
 *
 * This class only speaks HTTP. It does not decide *when* the model is used;
 * the engine stays deterministic and the model is opt-in. Being wrong here
 * must never break a reply, so every failure is a return value, not an
 * exception.
 *
 * Settings live in `config/chatbot.php` and, when an administrator saves the
 * page, in `files/chatbot/llm.php` (0600, PHP guard, outside git):
 *
 *   base_url, model, api_key, timeout, max_tokens, temperature,
 *   verify_tls, allow_remote, max_calls_per_minute
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Llm
{
    /** File written by the staff page, inside files/chatbot. */
    const NAME = 'llm.php';

    /** Same staff audit trail as the FAQ overlay. */
    const AUDIT = 'audit.ndjson.php';

    /** A reply larger than this is refused before it is parsed. */
    const MAX_RESPONSE = 262144;

    /** Longest single message sent to the model. */
    const MAX_MESSAGE = 4000;

    /** @var callable|null test seam: replaces the network */
    private static $transport = null;

    /** @var array<int,string> values the last sanitise() had to change */
    private static $problems = [];

    /** @var array resolved configuration */
    private $config;

    /** @var string settings file */
    private $path;

    /** @var array */
    private $settings;

    /**
     * @param array       $config
     * @param string|null $path settings file override (tests)
     */
    public function __construct(array $config = [], ?string $path = null)
    {
        $this->config = $config === [] ? Config::all() : array_merge(Config::defaults(), $config);
        $this->path = is_null($path) ? self::file($this->config) : $path;
        $this->settings = self::sanitise(self::merge($this->config, $this->path));
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    /**
     * Shipped defaults.
     *
     * @return array
     */
    public static function defaults(): array
    {
        $defaults = Config::defaults();
        $llm = isset($defaults['llm']) && is_array($defaults['llm']) ? $defaults['llm'] : [];

        return array_merge([
            'enabled' => false,
            'provider' => 'openai',
            'base_url' => 'http://127.0.0.1:11434/v1',
            'model' => '',
            'api_key' => '',
            'timeout' => 15,
            'max_tokens' => 256,
            'temperature' => 0.2,
            'verify_tls' => true,
            'allow_remote' => false,
            'max_calls_per_minute' => 6,
        ], $llm);
    }

    /**
     * Default location of the settings file.
     *
     * @return string
     */
    public static function defaultPath(): string
    {
        return LogStore::defaultDir() . DIRECTORY_SEPARATOR . self::NAME;
    }

    /**
     * @param array $config
     * @return string
     */
    public static function file(array $config = []): string
    {
        $file = trim((string)($config['llm']['file'] ?? ''));

        return $file !== '' ? $file : self::defaultPath();
    }

    /**
     * config.php <- files/chatbot/llm.php
     *
     * @param array  $config
     * @param string $path
     * @return array
     */
    public static function merge(array $config, string $path): array
    {
        $values = self::defaults();
        $block = isset($config['llm']) && is_array($config['llm']) ? $config['llm'] : [];
        $values = array_merge($values, $block);

        if ($path !== '' && is_file($path)) {
            try {
                $saved = include $path;
            } catch (\Throwable $error) {
                $saved = null;
            }

            if (is_array($saved)) {
                $values = array_merge($values, $saved);
            }
        }

        return $values;
    }

    /**
     * Repair settings: a URL that is not http(s), a timeout of zero or a
     * temperature of 40 would each turn into a confusing failure later.
     *
     * @param array $values
     * @return array
     */
    public static function sanitise(array $values): array
    {
        self::$problems = [];

        $values['enabled'] = self::flag($values['enabled'] ?? false, 'enabled');
        $values['verify_tls'] = self::flag($values['verify_tls'] ?? true, 'verify_tls');
        $values['allow_remote'] = self::flag($values['allow_remote'] ?? false, 'allow_remote');

        $provider = strtolower(trim((string)($values['provider'] ?? 'openai')));
        $values['provider'] = $provider === '' ? 'openai' : Str::slice($provider, 0, 32);

        $values['base_url'] = self::url((string)($values['base_url'] ?? ''));
        $values['model'] = trim(Str::slice((string)($values['model'] ?? ''), 0, 120));
        $values['api_key'] = trim(Str::slice((string)($values['api_key'] ?? ''), 0, 400));
        $values['timeout'] = self::number($values['timeout'] ?? 15, 3, 120, 15, 'timeout', true);
        $values['max_tokens'] = self::number($values['max_tokens'] ?? 256, 16, 4096, 256, 'max_tokens', true);
        $values['temperature'] = self::number($values['temperature'] ?? 0.2, 0.0, 2.0, 0.2, 'temperature', false);
        $values['max_calls_per_minute'] = self::number($values['max_calls_per_minute'] ?? 6, 1, 60, 6, 'max_calls_per_minute', true);

        self::$problems = array_values(array_unique(self::$problems));

        return $values;
    }

    /**
     * @return array<int,string>
     */
    public static function problems(): array
    {
        return self::$problems;
    }

    /**
     * @return array
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return bool
     */
    public function enabled(): bool
    {
        return !empty($this->settings['enabled']);
    }

    /**
     * Enough to attempt a call?
     *
     * @return bool
     */
    public function configured(): bool
    {
        return $this->enabled()
            && trim((string)$this->settings['base_url']) !== ''
            && trim((string)$this->settings['model']) !== '';
    }

    /**
     * Is the configured host allowed to receive a request?
     *
     * @return bool
     */
    public function reachable(): bool
    {
        return self::hostAllowed((string)parse_url((string)$this->settings['base_url'], PHP_URL_HOST), !empty($this->settings['allow_remote']));
    }

    /**
     * A summary that is safe to render: never the key itself.
     *
     * @return array
     */
    public function status(): array
    {
        $host = (string)parse_url((string)$this->settings['base_url'], PHP_URL_HOST);

        return [
            'enabled' => $this->enabled(),
            'configured' => $this->configured(),
            'provider' => (string)$this->settings['provider'],
            'base_url' => (string)$this->settings['base_url'],
            'host' => $host,
            'allowed' => $this->reachable(),
            'allow_remote' => !empty($this->settings['allow_remote']),
            'model' => (string)$this->settings['model'],
            'has_key' => (string)$this->settings['api_key'] !== '',
            'key' => self::mask((string)$this->settings['api_key']),
            'timeout' => (int)$this->settings['timeout'],
            'max_tokens' => (int)$this->settings['max_tokens'],
            'temperature' => (float)$this->settings['temperature'],
            'verify_tls' => !empty($this->settings['verify_tls']),
            'max_calls_per_minute' => (int)$this->settings['max_calls_per_minute'],
            'problems' => self::problems(),
        ];
    }

    /**
     * `sk-abcd…` becomes `••••wxyz`.
     *
     * @param string $key
     * @return string
     */
    public static function mask(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            return '';
        }

        if (Str::length($key) <= 4) {
            return '••••';
        }

        return '••••' . Str::slice($key, -4);
    }

    // -----------------------------------------------------------------
    // Address policy
    // -----------------------------------------------------------------

    /**
     * @param string $url
     * @return string normalised URL, or '' when it is not usable
     */
    private static function url(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (preg_match('/[\s<>"\']/', $url)) {
            self::$problems[] = 'base_url';

            return '';
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            self::$problems[] = 'base_url';

            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            self::$problems[] = 'base_url';

            return '';
        }

        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Local addresses are fine by default; the rest needs an explicit yes.
     * Cloud metadata endpoints are never allowed.
     *
     * @param string $host
     * @param bool   $allowRemote
     * @return bool
     */
    public static function hostAllowed(string $host, bool $allowRemote): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($host === '') {
            return false;
        }

        if (in_array($host, ['metadata.google.internal', 'metadata', 'instance-data', 'metadata.goog'], true)
            || $host === '169.254.169.254' || $host === '0.0.0.0' || $host === '::') {
            return false;
        }

        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)) {
            if (strpos($host, '169.254.') === 0) {
                return false;
            }

            if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)) {
                return true;
            }

            return $allowRemote;
        }

        if ($host === 'localhost' || $host === '::1' || substr($host, -6) === '.local' || strpos($host, '.') === false) {
            return true;
        }

        return $allowRemote;
    }

    // -----------------------------------------------------------------
    // Calls
    // -----------------------------------------------------------------

    /**
     * @param string $suffix
     * @return string
     */
    public function endpoint(string $suffix): string
    {
        return rtrim((string)$this->settings['base_url'], '/') . '/' . ltrim($suffix, '/');
    }

    /**
     * `GET /models`: does the server answer, how fast, and with which models?
     *
     * @return array{ok:bool,error?:string,message?:string,ms:int,models:array<int,string>,status?:int}
     */
    public function test(): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'DISABLED', 'ms' => 0, 'models' => []];
        }

        if (trim((string)$this->settings['base_url']) === '') {
            return ['ok' => false, 'error' => 'INVALID_URL', 'ms' => 0, 'models' => []];
        }

        if (!$this->reachable()) {
            return ['ok' => false, 'error' => 'REMOTE_BLOCKED', 'ms' => 0, 'models' => []];
        }

        $started = microtime(true);
        $response = $this->request('GET', $this->endpoint('models'), $this->headers(), null, min(20, (int)$this->settings['timeout']));
        $ms = (int)round((microtime(true) - $started) * 1000);

        if ($response['error'] !== '') {
            return ['ok' => false, 'error' => $response['error'], 'ms' => $ms, 'models' => []];
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return [
                'ok' => false,
                'error' => $this->statusCode($response['status']),
                'status' => $response['status'],
                'message' => $this->scrub(Str::slice(trim($response['body']), 0, 200)),
                'ms' => $ms,
                'models' => [],
            ];
        }

        $models = [];
        $decoded = json_decode($response['body'], true);

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach (array_slice($decoded['data'], 0, 40) as $row) {
                $id = is_array($row) ? (string)($row['id'] ?? '') : (string)$row;

                if ($id !== '') {
                    $models[] = $id;
                }
            }
        }

        return ['ok' => true, 'ms' => $ms, 'models' => $models, 'status' => $response['status']];
    }

    /**
     * One chat completion. Never throws; on failure the caller keeps the
     * deterministic answer.
     *
     * @param array $messages list of ['role' => 'user', 'content' => '...']
     * @param array $options  model, max_tokens, temperature
     * @return array{ok:bool,text?:string,error?:string,message?:string,ms:int,model?:string,usage?:array}
     */
    public function chat(array $messages, array $options = []): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'DISABLED', 'ms' => 0];
        }

        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'NOT_CONFIGURED', 'ms' => 0];
        }

        if (!$this->reachable()) {
            return ['ok' => false, 'error' => 'REMOTE_BLOCKED', 'ms' => 0];
        }

        $model = trim((string)($options['model'] ?? $this->settings['model']));
        $maxTokens = (int)($options['max_tokens'] ?? $this->settings['max_tokens']);
        $temperature = (float)($options['temperature'] ?? $this->settings['temperature']);

        $body = json_encode([
            'model' => $model,
            'messages' => self::messages($messages),
            'max_tokens' => max(16, min(4096, $maxTokens)),
            'temperature' => max(0.0, min(2.0, $temperature)),
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'BAD_REQUEST', 'ms' => 0];
        }

        $started = microtime(true);
        $response = $this->request('POST', $this->endpoint('chat/completions'), $this->headers(), $body, (int)$this->settings['timeout']);
        $ms = (int)round((microtime(true) - $started) * 1000);

        if ($response['error'] !== '') {
            return ['ok' => false, 'error' => $response['error'], 'ms' => $ms];
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return [
                'ok' => false,
                'error' => $this->statusCode($response['status']),
                'message' => $this->scrub(Str::slice(trim($response['body']), 0, 200)),
                'ms' => $ms,
            ];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'BAD_RESPONSE', 'ms' => $ms];
        }

        $text = '';

        if (isset($decoded['choices'][0]['message']['content'])) {
            $content = $decoded['choices'][0]['message']['content'];
            $text = is_string($content) ? $content : '';
        } elseif (isset($decoded['choices'][0]['text'])) {
            $text = (string)$decoded['choices'][0]['text'];
        }

        $text = trim(str_replace("\r\n", "\n", $text));

        if ($text === '') {
            return ['ok' => false, 'error' => 'EMPTY_CHOICE', 'ms' => $ms];
        }

        return [
            'ok' => true,
            'text' => $text,
            'model' => (string)($decoded['model'] ?? $model),
            'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            'ms' => $ms,
        ];
    }

    /**
     * @param array $messages
     * @return array
     */
    private static function messages(array $messages): array
    {
        $clean = [];

        foreach (array_slice($messages, 0, 8) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = strtolower(trim((string)($message['role'] ?? 'user')));

            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                continue;
            }

            $content = trim(Str::slice((string)($message['content'] ?? ''), 0, self::MAX_MESSAGE));

            if ($content === '') {
                continue;
            }

            $clean[] = ['role' => $role, 'content' => $content];
        }

        return $clean;
    }

    /**
     * @return array<int,string>
     */
    private function headers(): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        $key = (string)$this->settings['api_key'];

        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        return $headers;
    }

    /**
     * A provider error body can quote the key back at us. Never repeat it.
     *
     * @param string $text
     * @return string
     */
    private function scrub(string $text): string
    {
        $key = (string)$this->settings['api_key'];

        if ($key !== '' && strpos($text, $key) !== false) {
            $text = str_replace($key, self::mask($key), $text);
        }

        return $text;
    }

    /**
     * @param int $status
     * @return string
     */
    private function statusCode(int $status): string
    {
        if ($status === 401 || $status === 403) {
            return 'UNAUTHORISED';
        }

        if ($status === 404) {
            return 'NOT_FOUND';
        }

        if ($status === 429) {
            return 'RATE_LIMITED';
        }

        if ($status >= 500) {
            return 'SERVER_ERROR';
        }

        return 'HTTP_' . $status;
    }

    /**
     * Inject a fake transport (tests) or restore the real one.
     *
     * @param callable|null $transport function(string $method, string $url, array $headers, ?string $body, int $timeout): array
     * @return void
     */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * @param string      $method
     * @param string      $url
     * @param array       $headers
     * @param string|null $body
     * @param int         $timeout
     * @return array{status:int,body:string,error:string}
     */
    private function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $timeout = max(3, min(120, $timeout));

        if (!is_null(self::$transport)) {
            return self::response(call_user_func(self::$transport, $method, $url, $headers, $body, $timeout));
        }

        return function_exists('curl_init')
            ? $this->withCurl($method, $url, $headers, $body, $timeout)
            : $this->withStream($method, $url, $headers, $body, $timeout);
    }

    /**
     * @param mixed $result
     * @return array{status:int,body:string,error:string}
     */
    private static function response($result): array
    {
        if (!is_array($result)) {
            return ['status' => 0, 'body' => '', 'error' => 'TRANSPORT_ERROR'];
        }

        $body = is_string($result['body'] ?? null) ? $result['body'] : '';

        if (strlen($body) > self::MAX_RESPONSE) {
            return ['status' => 0, 'body' => '', 'error' => 'RESPONSE_TOO_LARGE'];
        }

        $error = (string)($result['error'] ?? '');

        // A transport may report its own words ("connection refused",
        // "Operation timed out"). Callers branch on a small set of codes.
        if ($error !== '' && !in_array($error, ['CONNECTION_FAILED', 'TRANSPORT_ERROR', 'TIMEOUT', 'RESPONSE_TOO_LARGE'], true)) {
            $error = stripos($error, 'timed out') !== false || stripos($error, 'timeout') !== false
                ? 'TIMEOUT'
                : 'CONNECTION_FAILED';
        }

        return [
            'status' => (int)($result['status'] ?? 0),
            'body' => $body,
            'error' => $error,
        ];
    }

    /**
     * @param string      $method
     * @param string      $url
     * @param array       $headers
     * @param string|null $body
     * @param int         $timeout
     * @return array{status:int,body:string,error:string}
     */
    private function withCurl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => 'TRANSPORT_ERROR'];
        }

        $verify = !empty($this->settings['verify_tls']);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'SLiMS-Chatbot/1.0',
        ]);

        if (!is_null($body)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $error = (string)curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($raw === false) {
            return ['status' => 0, 'body' => '', 'error' => $error === '' ? 'TRANSPORT_ERROR' : 'CONNECTION_FAILED'];
        }

        $raw = (string)$raw;

        if (strlen($raw) > self::MAX_RESPONSE) {
            return ['status' => 0, 'body' => '', 'error' => 'RESPONSE_TOO_LARGE'];
        }

        return ['status' => $status, 'body' => $raw, 'error' => ''];
    }

    /**
     * Fallback when curl is not compiled in: plain streams, same limits.
     *
     * @param string      $method
     * @param string      $url
     * @param array       $headers
     * @param string|null $body
     * @param int         $timeout
     * @return array{status:int,body:string,error:string}
     */
    private function withStream(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $verify = !empty($this->settings['verify_tls']);
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => is_null($body) ? '' : $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE + 1);

        if ($raw === false) {
            return ['status' => 0, 'body' => '', 'error' => 'CONNECTION_FAILED'];
        }

        if (strlen($raw) > self::MAX_RESPONSE) {
            return ['status' => 0, 'body' => '', 'error' => 'RESPONSE_TOO_LARGE'];
        }

        $status = 0;
        $lines = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];

        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', (string)$line, $match)) {
                $status = (int)$match[1];
            }
        }

        return ['status' => $status, 'body' => (string)$raw, 'error' => ''];
    }

    /**
     * @param mixed  $value
     * @param int    $min
     * @param int    $max
     * @param mixed  $default
     * @param string $label
     * @param bool   $integer
     * @return float|int
     */
    private static function number($value, $min, $max, $default, string $label, bool $integer)
    {
        if (is_bool($value) || !is_numeric($value)) {
            self::$problems[] = $label;

            return $default;
        }

        $number = $integer ? (int)$value : (float)$value;
        $clamped = max($min, min($max, $number));

        if ($integer ? $clamped !== $number : abs($clamped - $number) > 0.000001) {
            self::$problems[] = $label;
        }

        return $integer ? (int)$clamped : round($clamped, 2);
    }

    /**
     * @param mixed  $value
     * @param string $label
     * @return bool
     */
    private static function flag($value, string $label): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }

        $text = strtolower(trim((string)$value));

        if (in_array($text, ['1', 'true', 'on', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($text, ['0', 'false', 'off', 'no', 'n', ''], true)) {
            return false;
        }

        self::$problems[] = $label;

        return false;
    }

    // -----------------------------------------------------------------
    // Saving
    // -----------------------------------------------------------------

    /**
     * Save what the staff page submitted. A blank key keeps the old one, so
     * the page never has to send the secret back to the browser.
     *
     * @param array $values
     * @param array $audit
     * @return bool
     */
    public function save(array $values, array $audit = []): bool
    {
        $merged = array_merge($this->settings, $values);

        if (trim((string)($values['api_key'] ?? '')) === '') {
            $merged['api_key'] = (string)$this->settings['api_key'];
        }

        $clean = self::sanitise($merged);

        if (trim((string)$clean['base_url']) === '' && trim((string)($values['base_url'] ?? '')) !== '') {
            return false;
        }

        $dir = dirname($this->path);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }

        $export = [
            'enabled' => (bool)$clean['enabled'],
            'provider' => (string)$clean['provider'],
            'base_url' => (string)$clean['base_url'],
            'model' => (string)$clean['model'],
            'api_key' => (string)$clean['api_key'],
            'timeout' => (int)$clean['timeout'],
            'max_tokens' => (int)$clean['max_tokens'],
            'temperature' => (float)$clean['temperature'],
            'verify_tls' => (bool)$clean['verify_tls'],
            'allow_remote' => (bool)$clean['allow_remote'],
            'max_calls_per_minute' => (int)$clean['max_calls_per_minute'],
            'updated_at' => gmdate('c'),
        ];

        $body = "<?php\n/**\n * Chatbot LLM connection, written by System -> Chatbot LLM.\n"
            . " * Generated " . $export['updated_at'] . ". Delete this file to fall back to config/chatbot.php.\n"
            . " * Contains an API key: PHP-only, 0600, outside git.\n */\n\nreturn "
            . var_export($export, true) . ";\n";

        $temp = $this->path . '.tmp-' . substr(sha1(uniqid('', true)), 0, 8);
        $handle = @fopen($temp, 'wb');

        if (!is_resource($handle)) {
            return false;
        }

        $written = @fwrite($handle, $body);
        @fflush($handle);
        @fclose($handle);

        if ($written !== strlen($body)) {
            @unlink($temp);

            return false;
        }

        @chmod($temp, 0600);

        if (is_file($this->path)) {
            @copy($this->path, $this->path . '.bak');
            @chmod($this->path . '.bak', 0600);
        }

        if (!@rename($temp, $this->path)) {
            @unlink($temp);

            return false;
        }

        @chmod($this->path, 0600);

        $this->settings = $clean;

        if ($audit !== []) {
            $this->appendAudit($export, $audit);
        }

        return true;
    }

    /**
     * Who changed the connection, when, and to which host. Never the key.
     *
     * @param array $settings
     * @param array $audit
     * @return void
     */
    private function appendAudit(array $settings, array $audit): void
    {
        $path = dirname($this->path) . DIRECTORY_SEPARATOR . self::AUDIT;
        $handle = @fopen($path, 'ab');

        if (!is_resource($handle)) {
            return;
        }

        $row = [
            'ts' => gmdate('c'),
            'action' => (string)($audit['action'] ?? 'llm.save'),
            'target' => (string)parse_url((string)$settings['base_url'], PHP_URL_HOST) . '/' . (string)$settings['model'],
            'detail' => !empty($settings['enabled']) ? 'enabled' : 'disabled',
            'actor' => Str::slice((string)($audit['actor'] ?? ''), 0, 60),
            'version' => 0,
            'hash' => substr(sha1(json_encode($settings)), 0, 16),
        ];

        if (@flock($handle, LOCK_EX)) {
            if ((int)@filesize($path) === 0) {
                @fwrite($handle, "<?php exit; ?>\n");
            }

            @fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);
    }
}
