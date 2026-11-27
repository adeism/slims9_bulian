<?php
/**
 * Webhook entry point for messaging channels
 *
 * Registered by `chatbot.plugin.php` through `custom_api_route`, so it is
 * reachable without a browser session:
 *
 *   POST index.php?p=api/chatbot/v1/message      X-Chatbot-Token: …
 *   GET  index.php?p=api/chatbot/v1/message      WhatsApp Cloud verification
 *
 * The bridge is inert unless `channel.enabled` is true **and** a token is
 * configured, exactly like the CLI-Anything HTTP bridge.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Webhook
{
    /**
     * @return void
     */
    public static function dispatch()
    {
        $config = Config::all();
        $payload = json_decode(self::rawBody(), true);

        $result = Channel::handle(
            is_array($payload) ? $payload : [],
            $config,
            null,
            self::headers()
        );

        Http::respond([
            'ok' => (bool)$result['ok'],
            'role' => (string)$result['role'],
            'reply' => (string)$result['reply'],
            'pending' => (bool)$result['pending'],
            'intent' => $result['intent'],
            'error' => $result['error'] ?? null,
        ], (int)$result['status']);
    }

    /**
     * WhatsApp Cloud API subscription check (`hub.challenge`).
     *
     * @return void
     */
    public static function verify()
    {
        $channel = Channel::config(Config::all());

        $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        $expected = trim((string)($channel['verify_token'] ?? ''));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: text/plain; charset=utf-8');
            }

            echo $challenge;
            exit;
        }

        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'forbidden';
        exit;
    }

    /**
     * @return string
     */
    private static function rawBody(): string
    {
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            return '';
        }

        $raw = @file_get_contents('php://input');

        return is_string($raw) ? $raw : '';
    }

    /**
     * @return array
     */
    private static function headers(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos((string)$key, 'HTTP_') !== 0) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string)$key, 5)))));
            $headers[$name] = $value;
        }

        return $headers;
    }
}
