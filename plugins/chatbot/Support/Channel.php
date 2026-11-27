<?php
/**
 * Messaging channels (WhatsApp, Telegram, generic gateways)
 *
 * The same engine, reached from a webhook instead of a browser. Three things
 * differ, and only three:
 *
 *   1. **Identity.** A chat app knows a phone number or a chat id, not a
 *      SLiMS session. The mapping is an explicit allow-list in
 *      `config/chatbot.php`; nothing is matched automatically, so a spoofed
 *      phone number cannot become a member account.
 *   2. **Confirmation.** There is no cookie to bind a token to, so the
 *      pending action is stored server-side per sender (`ChannelStore`) and
 *      the user confirms by replying "YA".
 *   3. **Formatting.** Replies are plain text: facts as `Label: value`,
 *      records as bullets, notices prefixed with a marker.
 *
 * Everything else — the role matrix, the write gates, the audit trail, the
 * masking — is the engine from the browser version.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Channel
{
    /** Hard ceiling for one reply (SMS/WhatsApp friendly). */
    const MAX_REPLY = 1400;

    /**
     * Channel configuration merged over the defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config(array $config = []): array
    {
        $defaults = Config::defaults();
        $defaults = isset($defaults['channel']) && is_array($defaults['channel']) ? $defaults['channel'] : [];
        $provided = isset($config['channel']) && is_array($config['channel']) ? $config['channel'] : [];

        return array_merge($defaults, $provided);
    }

    /**
     * Handle one webhook payload.
     *
     * @param array       $payload raw JSON body (native or normalized)
     * @param array       $config  chatbot configuration
     * @param object|null $service CLI-Anything service (tests inject a fake)
     * @param array       $headers request headers
     * @param int|null    $now     clock override
     * @return array{ok:bool,status:int,role:string,reply:string,pending:bool,intent:?string,error?:array}
     */
    public static function handle(array $payload, array $config = [], $service = null, array $headers = [], ?int $now = null): array
    {
        $now = is_null($now) ? time() : $now;
        $config = $config === [] ? Config::all() : array_merge(Config::defaults(), $config);
        $channelConfig = self::config($config);
        $locale = Lang::locale($config);

        if (empty($channelConfig['enabled'])) {
            return self::failure('CHATBOT_DISABLED', Lang::t($locale, 'ui_disabled'), 404);
        }

        if (trim((string)($channelConfig['token'] ?? '')) === '' || !self::authorized($headers, (string)$channelConfig['token'])) {
            return self::failure('UNAUTHORIZED', Lang::t($locale, 'channel_unauthorized'), 401);
        }

        $message = self::normalize($payload);

        if ($message['text'] === '') {
            return self::failure('EMPTY_MESSAGE', Lang::t($locale, 'channel_missing'), 422);
        }

        $actor = self::identity($message['channel'], $message['from'], $channelConfig);
        $key = $message['channel'] . '|' . $message['from'];
        $store = new ChannelStore($channelConfig);

        try {
            if (!$store->allow($key, 'messages', (int)($config['rate_limit_per_minute'] ?? 20), 60, $now)) {
                return self::failure('RATE_LIMITED', Lang::t($locale, 'rate_limited'), 429);
            }

            $engine = new Engine($actor, $config, $service, true);
            $response = null;
            $pending = false;

            if (self::isConfirm($message['text'], $channelConfig)) {
                $action = $store->takeAction($key, $now);

                if (is_null($action)) {
                    return self::answer($actor['role'], Lang::t($locale, 'channel_expired'), false, null);
                }

                if (!$store->allow($key, 'writes', (int)($config['max_writes_per_hour'] ?? 5), 3600, $now)) {
                    return self::failure('RATE_LIMITED', Lang::t($locale, 'rate_limited'), 429);
                }

                $response = $engine->commitWrite([
                    'name' => (string)($action['intent'] ?? ''),
                    'params' => (array)($action['params'] ?? []),
                ]);
            } elseif (self::isCancel($message['text'], $channelConfig)) {
                $store->takeAction($key, $now);

                return self::answer($actor['role'], Lang::t($locale, 'channel_cancelled'), false, null);
            } else {
                $response = $engine->handle($message['text']);

                if (!empty($response['pending']['action'])) {
                    $store->putAction($key, (array)$response['pending']['action'], (int)($config['confirm_ttl'] ?? 180), $now);
                    $pending = true;
                }
            }

            return self::answer(
                (string)($response['role'] ?? $actor['role']),
                self::render($response, $locale, $pending),
                $pending,
                (string)($response['meta']['intent'] ?? null),
                $response['ok'] ?? true
            );
        } catch (\Throwable $error) {
            return self::failure('UNEXPECTED_ERROR', Lang::t($locale, 'error_generic'), 500);
        }
    }

    /**
     * Normalize Telegram, WhatsApp Cloud API, common Indonesian gateways,
     * and our own `{channel, from, text}` shape.
     *
     * @param array $payload
     * @return array{channel:string,from:string,text:string}
     */
    public static function normalize(array $payload): array
    {
        $channel = strtolower(trim((string)($payload['channel'] ?? '')));
        $from = trim((string)($payload['from'] ?? ''));
        $text = trim((string)($payload['text'] ?? ''));

        // Telegram: {"message": {"chat": {"id": …}, "text": "…"}}
        if ($text === '' && isset($payload['message']) && is_array($payload['message'])) {
            $channel = $channel === '' ? 'telegram' : $channel;
            $from = $from === '' ? trim((string)($payload['message']['chat']['id'] ?? '')) : $from;
            $text = trim((string)($payload['message']['text'] ?? ''));
        }

        // WhatsApp Cloud API: entry[0].changes[0].value.messages[0]
        if ($text === '' && isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            $incoming = $payload['entry'][0]['changes'][0]['value']['messages'][0];
            $channel = $channel === '' ? 'whatsapp' : $channel;
            $from = $from === '' ? trim((string)($incoming['from'] ?? '')) : $from;
            $text = trim((string)($incoming['text']['body'] ?? ''));
        }

        // Wablas / Fonnte / most local gateways: {"phone": "…", "message": "…"}
        if ($text === '' && isset($payload['phone'], $payload['message']) && is_string($payload['message'])) {
            $channel = $channel === '' ? 'whatsapp' : $channel;
            $from = $from === '' ? trim((string)$payload['phone']) : $from;
            $text = trim((string)$payload['message']);
        }

        return [
            'channel' => $channel === '' ? 'generic' : $channel,
            'from' => $from,
            'text' => $text,
        ];
    }

    /**
     * @param array  $headers
     * @param string $expected
     * @return bool
     */
    public static function authorized(array $headers, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }

        $provided = '';

        foreach ($headers as $name => $value) {
            $name = strtolower((string)$name);

            if ($name === 'x-chatbot-token') {
                $provided = trim((string)$value);
                break;
            }

            if ($name === 'authorization' && preg_match('/^Bearer\s+(.+)$/i', trim((string)$value), $match)) {
                $provided = trim($match[1]);
            }
        }

        if ($provided === '' && isset($_SERVER['HTTP_X_CHATBOT_TOKEN'])) {
            $provided = trim((string)$_SERVER['HTTP_X_CHATBOT_TOKEN']);
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * Resolve a sender to a role. Unknown senders are guests.
     *
     * @param string $channel
     * @param string $from
     * @param array  $channelConfig
     * @return array
     */
    public static function identity(string $channel, string $from, array $channelConfig): array
    {
        $key = strtolower($channel) . ':' . strtolower(trim($from));

        $maps = [
            'staff' => self::identityMap($channelConfig['staff'] ?? []),
            'members' => self::identityMap($channelConfig['members'] ?? []),
        ];

        if (isset($maps['staff'][$key]) && $maps['staff'][$key] !== '') {
            return Actor::make(Policy::STAFF, (string)$maps['staff'][$key], 'Channel');
        }

        if (isset($maps['members'][$key]) && $maps['members'][$key] !== '') {
            return Actor::make(Policy::MEMBER, (string)$maps['members'][$key], '');
        }

        return Actor::anonymous(Policy::GUEST);
    }

    /**
     * Render an engine response as messaging text.
     *
     * @param array  $response
     * @param string $locale
     * @param bool   $pending
     * @return string
     */
    public static function render(array $response, string $locale, bool $pending = false): string
    {
        $lines = [];
        $text = trim((string)($response['reply']['text'] ?? ''));

        if ($text !== '') {
            $lines[] = $text;
        }

        $markers = [
            'info' => '[i]',
            'warn' => '[!]',
            'success' => '[ok]',
            'error' => '[x]',
        ];

        foreach ((array)($response['reply']['blocks'] ?? []) as $block) {
            $type = (string)($block['type'] ?? '');

            if ($type === 'notice' && !empty($block['text'])) {
                $body = trim((string)$block['text']);

                if ($body === $text) {
                    continue;
                }

                $lines[] = ($markers[(string)($block['tone'] ?? 'info')] ?? '[i]') . ' ' . $body;
            }

            if ($type === 'facts') {
                foreach ((array)($block['items'] ?? []) as $fact) {
                    $lines[] = trim((string)($fact['label'] ?? '')) . ': ' . trim((string)($fact['value'] ?? '-'));
                }
            }

            if ($type === 'list') {
                foreach ((array)($block['items'] ?? []) as $item) {
                    $line = '• ' . trim((string)($item['title'] ?? '-'));
                    $meta = trim((string)($item['meta'] ?? ''));

                    if ($meta !== '') {
                        $line .= "\n  " . $meta;
                    }

                    if (!empty($item['badge'])) {
                        $line .= ' [' . trim((string)$item['badge']) . ']';
                    }

                    $lines[] = $line;
                }
            }

            if ($type === 'link' && !empty($block['href'])) {
                $lines[] = trim((string)($block['label'] ?? '')) . ': ' . (string)$block['href'];
            }
        }

        $output = implode("\n", $lines);

        if ($pending) {
            $output .= "\n\n" . Lang::t($locale, 'channel_confirm_hint');
        }

        if (Str::length($output) > self::MAX_REPLY) {
            $output = Str::slice($output, 0, self::MAX_REPLY - 1) . '…';
        }

        return $output;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param string $text
     * @param array  $channelConfig
     * @return bool
     */
    private static function isConfirm(string $text, array $channelConfig): bool
    {
        return in_array(self::cleanWord($text), self::words($channelConfig['confirm_words'] ?? []), true);
    }

    /**
     * @param string $text
     * @param array  $channelConfig
     * @return bool
     */
    private static function isCancel(string $text, array $channelConfig): bool
    {
        return in_array(self::cleanWord($text), self::words($channelConfig['cancel_words'] ?? []), true);
    }

    /**
     * @param string $text
     * @return string
     */
    private static function cleanWord(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($text)));
    }

    /**
     * @param mixed $words
     * @return array
     */
    private static function words($words): array
    {
        $out = [];

        foreach ((array)$words as $word) {
            $out[] = self::cleanWord((string)$word);
        }

        return $out;
    }

    /**
     * @param mixed $map
     * @return array
     */
    private static function identityMap($map): array
    {
        $out = [];

        foreach ((array)$map as $key => $value) {
            $normalized = strtolower(str_replace('|', ':', trim((string)$key)));
            $out[$normalized] = $value;
        }

        return $out;
    }

    /**
     * @param string      $role
     * @param string      $reply
     * @param bool        $pending
     * @param string|null $intent
     * @param bool        $ok
     * @return array
     */
    private static function answer(string $role, string $reply, bool $pending, ?string $intent, bool $ok = true): array
    {
        return [
            'ok' => $ok,
            'status' => $ok ? 200 : 422,
            'role' => Intent::normaliseRole($role),
            'reply' => $reply,
            'pending' => $pending,
            'intent' => $intent === '' ? null : $intent,
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @param int    $status
     * @return array
     */
    private static function failure(string $code, string $message, int $status): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'role' => Policy::GUEST,
            'reply' => $message,
            'pending' => false,
            'intent' => null,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
