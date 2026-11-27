<?php
/**
 * Single-use confirmation tokens
 *
 * A write is never executed straight from a message. The chat first runs
 * the dry-run (`--commit` absent), shows the plan, and hands the browser a
 * token that:
 *
 *   - is signed with a per-session secret, so it cannot be forged;
 *   - carries the exact action (intent + params + role + actor);
 *   - expires after `confirm_ttl` seconds;
 *   - can be consumed once (the nonce is removed from the session).
 *
 * This is what stops a replayed request, a double click, or "yes" being
 * read as consent for a different plan.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Confirm
{
    const SESSION_KEY = 'chatbot_pending';
    const SECRET_KEY = 'chatbot_secret';

    /**
     * @param array    $action intent, params, role, actor_id
     * @param int      $ttl    seconds
     * @param int|null $now    clock override for tests
     * @return string
     */
    public static function issue(array $action, int $ttl, ?int $now = null): string
    {
        $ttl = max(30, min(900, $ttl));
        $now = is_null($now) ? time() : $now;
        $nonce = bin2hex(random_bytes(8));

        $payload = [
            'action' => $action,
            'nonce' => $nonce,
            'exp' => $now + $ttl,
        ];

        $store = &self::store();

        // Drop tokens that expired without being used (a tampered or
        // abandoned confirmation would otherwise linger for the session).
        foreach ($store as $key => $entry) {
            if ((int)($entry['exp'] ?? 0) < $now) {
                unset($store[$key]);
            }
        }

        $store[$nonce] = $payload;
        unset($store);

        $body = self::encode($payload);

        return $body . '.' . self::sign($body);
    }

    /**
     * @param string   $token
     * @param int|null $now clock override for tests
     * @return array{valid:bool,reason:string,payload:array|null}
     */
    public static function consume(string $token, ?int $now = null): array
    {
        $now = is_null($now) ? time() : $now;
        $parts = explode('.', $token);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return self::outcome(false, 'invalid');
        }

        list($body, $signature) = $parts;

        if (!hash_equals(self::sign($body), $signature)) {
            return self::outcome(false, 'invalid');
        }

        $payload = json_decode(self::decode($body), true);

        if (!is_array($payload) || empty($payload['nonce']) || !isset($payload['action'])) {
            return self::outcome(false, 'invalid');
        }

        $store = &self::store();
        $stored = $store[$payload['nonce']] ?? null;
        unset($store[$payload['nonce']]);
        unset($store);

        if (is_null($stored)) {
            return self::outcome(false, 'reused');
        }

        if ((int)$payload['exp'] < $now) {
            return self::outcome(false, 'expired');
        }

        if (!is_array($stored['action'] ?? null) || $stored['action'] !== $payload['action']) {
            return self::outcome(false, 'mismatch');
        }

        return [
            'valid' => true,
            'reason' => 'ok',
            'payload' => $payload,
        ];
    }

    /**
     * Drop every pending token (logout, tests).
     *
     * @return void
     */
    public static function clear()
    {
        $_SESSION[self::SESSION_KEY] = [];
    }

    /**
     * @return int
     */
    public static function pendingCount(): int
    {
        return isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])
            ? count($_SESSION[self::SESSION_KEY])
            : 0;
    }

    /**
     * @param bool        $valid
     * @param string      $reason
     * @return array
     */
    private static function outcome(bool $valid, string $reason): array
    {
        return ['valid' => $valid, 'reason' => $reason, 'payload' => null];
    }

    /**
     * @return array
     */
    private static function &store(): array
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * @return string
     */
    private static function secret(): string
    {
        if (empty($_SESSION[self::SECRET_KEY]) || !is_string($_SESSION[self::SECRET_KEY])) {
            $_SESSION[self::SECRET_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SECRET_KEY];
    }

    /**
     * @param string $body
     * @return string
     */
    private static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::secret());
    }

    /**
     * @param array $payload
     * @return string
     */
    private static function encode(array $payload): string
    {
        $json = (string)json_encode($payload);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param string $body
     * @return string
     */
    private static function decode(string $body): string
    {
        $padded = strtr($body, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        return (string)base64_decode($padded, true);
    }
}
