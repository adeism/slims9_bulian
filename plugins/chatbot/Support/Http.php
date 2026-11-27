<?php
/**
 * JSON endpoint shared by the OPAC page and the staff page
 *
 * Both surfaces post `{message, confirm, csrf_token}` here and receive the
 * Engine response. The endpoint intentionally does *not* live in the
 * `custom_api_route` bridge: it must run inside the normal SLiMS session
 * (memberArea or admin) so the role comes from the session cookie, not
 * from a token in the request body.
 *
 * `process()` is pure with respect to output (it returns the payload), so
 * the whole request pipeline can be unit tested; `handle()` is the thin
 * wrapper that writes JSON and exits.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Http
{
    const CSRF_KEY = 'chatbot_csrf';

    /**
     * Is this request for the chat endpoint? (`?action=ask`)
     *
     * @return bool
     */
    public static function isAsk(): bool
    {
        return isset($_GET['action']) && $_GET['action'] === 'ask';
    }

    /**
     * Full request handling: parse, validate, answer, exit.
     *
     * @param array       $actor
     * @param string      $surface opac|admin
     * @param object|null $service
     * @return void
     */
    public static function handle(array $actor, string $surface = 'opac', $service = null)
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'POST';

        if ($method !== 'POST') {
            self::respond([
                'ok' => false,
                'error' => [
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => 'The chat endpoint only accepts POST.',
                ],
            ], 405);
        }

        $payload = self::process(self::readInput(), $actor, $surface, $service);

        self::respond($payload, $payload['ok'] ? 200 : 422);
    }

    /**
     * Validate a request and run the engine.
     *
     * @param array       $input
     * @param array       $actor
     * @param string      $surface
     * @param object|null $service
     * @param array       $config
     * @return array
     */
    public static function process(array $input, array $actor, string $surface = 'opac', $service = null, array $config = []): array
    {
        $config = $config === [] ? Config::all() : array_merge(Config::defaults(), $config);
        $locale = Lang::locale($config);

        $surfaceKey = $surface === 'admin' ? 'admin' : 'opac';

        if (empty($config['enabled']) || empty($config[$surfaceKey])) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'CHATBOT_DISABLED',
                    'message' => Lang::t($locale, 'ui_disabled'),
                ],
            ];
        }

        $csrf = (string)($input['csrf_token'] ?? '');

        if (!self::validCsrf($csrf)) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'CSRF_FAILED',
                    'message' => Lang::t($locale, 'ui_session_expired'),
                ],
            ];
        }

        $message = isset($input['message']) ? (string)$input['message'] : '';
        $confirm = isset($input['confirm']) ? (string)$input['confirm'] : null;

        // Thumbs up/down: a separate POST that never runs the engine, so it
        // cannot be used to smuggle a question past the rate limit.
        if (isset($input['feedback'])) {
            $log = new LogStore($config);
            $req = (string)($input['req'] ?? '');
            $value = in_array($input['feedback'], ['up', 'down'], true) ? (string)$input['feedback'] : '';

            if ($value === '' || !LogStore::validReq($req) || !$log->feedback($req, $value, [
                'surface' => $surfaceKey,
                'role' => (string)($actor['role'] ?? 'guest'),
                'actor_id' => (string)($actor['member_id'] ?? ($actor['id'] ?? '')),
            ])) {
                return [
                    'ok' => false,
                    'error' => [
                        'code' => 'FEEDBACK_INVALID',
                        'message' => Lang::t($locale, 'feedback_invalid'),
                    ],
                ];
            }

            return ['ok' => true, 'feedback' => $value, 'meta' => ['request' => $req]];
        }

        if (trim($message) === '' && ($confirm === null || $confirm === '')) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'EMPTY_MESSAGE',
                    'message' => Lang::t($locale, 'not_understood', [
                        'examples' => Lang::t($locale, 'examples_' . Intent::normaliseRole((string)($actor['role'] ?? 'guest'))),
                    ]),
                ],
            ];
        }

        $started = microtime(true);
        $req = LogStore::reqId();

        $engine = new Engine($actor, $config, $service);
        $response = $engine->handle($message, $confirm);
        $response['surface'] = $surface;

        $intent = (string)($response['meta']['intent'] ?? Intent::NONE);
        $confirmState = '';

        if (!empty($response['pending'])) {
            $confirmState = 'planned';
        } elseif (!empty($response['meta']['confirmed'])) {
            $confirmState = 'committed';
        }

        $log = new LogStore($config);
        $log->record([
            'req' => $req,
            'surface' => $surfaceKey,
            'role' => (string)($actor['role'] ?? 'guest'),
            'actor_id' => (string)($actor['member_id'] ?? ($actor['id'] ?? '')),
            'locale' => $locale,
            'q' => $message,
            'intent' => $intent,
            'faq_id' => (string)($response['meta']['faq_id'] ?? ''),
            'stage' => (string)($response['meta']['stage'] ?? ''),
            'ms' => (int)round((microtime(true) - $started) * 1000),
            'write' => Policy::isWrite($intent),
            'confirm' => $confirmState,
            'guard' => (string)($response['meta']['guard'] ?? ''),
            'err' => $response['ok'] ? '' : (string)($response['error']['code'] ?? ''),
        ]);

        $response['meta']['request'] = $req;

        return $response;
    }

    /**
     * Read the request body: JSON first, then form encoded.
     *
     * @return array
     */
    public static function readInput(): array
    {
        $raw = '';

        if (isset($_SERVER['REQUEST_METHOD'])) {
            $raw = (string)file_get_contents('php://input');
        }

        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $parsed = [];
            parse_str($raw, $parsed);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        return isset($_POST) && is_array($_POST) ? $_POST : [];
    }

    /**
     * The token embedded in the rendered page. Stable for the session so a
     * long conversation keeps working.
     *
     * @return string
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(16));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    /**
     * @param string $submitted
     * @return bool
     */
    public static function validCsrf(string $submitted): bool
    {
        $stored = isset($_SESSION[self::CSRF_KEY]) ? (string)$_SESSION[self::CSRF_KEY] : '';

        if ($stored === '' || $submitted === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    /**
     * Emit JSON and stop the request before the template renders.
     *
     * @param array $payload
     * @param int   $status
     * @return void
     */
    public static function respond(array $payload, int $status = 200)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
