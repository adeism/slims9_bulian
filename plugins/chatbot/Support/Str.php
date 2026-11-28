<?php
/**
 * Small string helpers with an mbstring fallback
 *
 * SLiMS itself assumes mbstring, but the chat also runs in stripped-down
 * PHP builds (CI, php-wasm) where the extension is missing. Falling back to
 * byte functions keeps the router working there; UTF-8 text may be counted
 * in bytes, which is acceptable for a length guard.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Str
{
    /**
     * @param string $value
     * @return string
     */
    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * @param string $value
     * @return int
     */
    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * @param string   $value
     * @param int      $start
     * @param int|null $length
     * @return string
     */
    public static function slice(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return is_null($length)
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return is_null($length) ? substr($value, $start) : substr($value, $start, $length);
    }
}
