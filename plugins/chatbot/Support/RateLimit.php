<?php
/**
 * Per-session rate limiting
 *
 * The chat is reachable without a token, so a single session must not be
 * able to hammer the database. Two buckets are used: one for messages and
 * one for confirmations (writes are far scarcer than reads).
 *
 * @license GPLv3
 */

namespace Chatbot;

final class RateLimit
{
    const KEY = 'chatbot_rate';
    const MESSAGES = 'messages';
    const WRITES = 'writes';
    const LLM = 'llm';

    /**
     * Count one hit. Returns false when the bucket is exhausted.
     *
     * @param string   $bucket
     * @param int      $limit
     * @param int      $windowSeconds
     * @param int|null $now
     * @return bool
     */
    public static function hit(string $bucket, int $limit, int $windowSeconds, ?int $now = null): bool
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $now = is_null($now) ? time() : $now;

        $state = &self::store();
        $entry = $state[$bucket] ?? ['start' => $now, 'count' => 0];

        if (($now - (int)$entry['start']) >= $windowSeconds) {
            $entry = ['start' => $now, 'count' => 0];
        }

        if ((int)$entry['count'] >= $limit) {
            $state[$bucket] = $entry;
            unset($state);

            return false;
        }

        $entry['count'] = (int)$entry['count'] + 1;
        $state[$bucket] = $entry;
        unset($state);

        return true;
    }

    /**
     * @return void
     */
    public static function reset()
    {
        $_SESSION[self::KEY] = [];
    }

    /**
     * @return array
     */
    private static function &store(): array
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }

        if (!isset($_SESSION[self::KEY]) || !is_array($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = [];
        }

        return $_SESSION[self::KEY];
    }
}
