<?php
/**
 * Who may open which staff surface
 *
 * The chat itself is gated by the SLiMS session (`Actor::fromAdmin`), but the
 * extra staff pages have their own switches: a library may want the chat and
 * the history while corrections stay closed, for example.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Admin
{
    /** @var string[] */
    const FLAGS = ['admin_history', 'admin_overlay', 'admin_rules'];

    /**
     * @param array  $actor
     * @param array  $config
     * @param string $flag admin_history|admin_overlay|admin_rules
     * @return bool
     */
    public static function can(array $actor, array $config, string $flag): bool
    {
        if (!in_array($flag, self::FLAGS, true)) {
            return false;
        }

        if (Intent::normaliseRole((string)($actor['role'] ?? 'guest')) !== Policy::STAFF) {
            return false;
        }

        $config = $config === [] ? Config::all() : $config;

        return !empty($config['enabled'])
            && !empty($config['admin'])
            && !empty($config[$flag]);
    }

    /**
     * A short, non-secret label for the audit trail.
     *
     * @param array $actor
     * @return string
     */
    public static function label(array $actor): string
    {
        $name = trim((string)($actor['username'] ?? ($actor['name'] ?? '')));

        if ($name === '') {
            return 'staff';
        }

        return Str::slice($name, 0, 60);
    }
}
