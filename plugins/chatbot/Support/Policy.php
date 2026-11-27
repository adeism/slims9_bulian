<?php
/**
 * Role policy: who may ask what
 *
 * The role is always derived from the SLiMS session on the server, never
 * from the request body. A guest cannot reach a member answer by editing
 * the payload, and a member cannot widen a query to somebody else's
 * account: `scope()` overwrites the member id with the session value.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Policy
{
    const GUEST = 'guest';
    const MEMBER = 'member';
    const STAFF = 'staff';

    /**
     * Intent allow-list per role.
     *
     * @var array
     */
    private static $matrix = [
        self::GUEST => [
            Intent::GREETING,
            Intent::THANKS,
            Intent::HELP,
            Intent::BIBLIO_LIST,
            Intent::BIBLIO_GET,
            Intent::ITEM_GET,
            Intent::NONE,
        ],
        self::MEMBER => [
            Intent::GREETING,
            Intent::THANKS,
            Intent::HELP,
            Intent::WHOAMI,
            Intent::BIBLIO_LIST,
            Intent::BIBLIO_GET,
            Intent::ITEM_GET,
            Intent::MY_LOANS,
            Intent::MY_PROFILE,
            Intent::MY_EXTEND,
            Intent::NONE,
        ],
        self::STAFF => [
            Intent::GREETING,
            Intent::THANKS,
            Intent::HELP,
            Intent::WHOAMI,
            Intent::BIBLIO_LIST,
            Intent::BIBLIO_GET,
            Intent::ITEM_GET,
            Intent::LOAN_LIST,
            Intent::MEMBER_GET,
            Intent::REPORT_SUMMARY,
            Intent::DOCTOR,
            Intent::LOAN_CHECKOUT,
            Intent::LOAN_CHECKIN,
            Intent::LOAN_EXTEND,
            Intent::NONE,
        ],
    ];

    /**
     * @param string $role
     * @param string $intent
     * @return bool
     */
    public static function allows(string $role, string $intent): bool
    {
        $role = Intent::normaliseRole($role);

        return in_array($intent, self::$matrix[$role] ?? [], true);
    }

    /**
     * Intents that write to the database.
     *
     * @param string $intent
     * @return bool
     */
    public static function isWrite(string $intent): bool
    {
        return in_array($intent, [
            Intent::LOAN_CHECKOUT,
            Intent::LOAN_CHECKIN,
            Intent::LOAN_EXTEND,
            Intent::MY_EXTEND,
        ], true);
    }

    /**
     * Which configuration gate protects this write?
     *
     * @param string $intent
     * @return string|null 'admin' or 'member'
     */
    public static function writeGate(string $intent)
    {
        if (!self::isWrite($intent)) {
            return null;
        }

        return $intent === Intent::MY_EXTEND ? 'member' : 'admin';
    }

    /**
     * Is the write gate open for this role?
     *
     * @param string $role
     * @param string $intent
     * @param array  $config
     * @return bool
     */
    public static function writeAllowed(string $role, string $intent, array $config): bool
    {
        $role = Intent::normaliseRole($role);

        if ($role === self::GUEST || !self::isWrite($intent)) {
            return false;
        }

        if (self::writeGate($intent) === 'member') {
            return (bool)($config['allow_member_write'] ?? false);
        }

        return $role === self::STAFF && (bool)($config['allow_admin_write'] ?? false);
    }

    /**
     * Why a role may not run an intent. Used to pick the right sentence:
     * a guest gets a login hint, a member a "ask the desk" hint.
     *
     * @param string $role
     * @param string $intent
     * @return string need_login|denied_member|denied_guest|denied_role
     */
    public static function denialReason(string $role, string $intent): string
    {
        $role = Intent::normaliseRole($role);

        if ($role === self::GUEST) {
            $memberOnly = [
                Intent::MY_LOANS,
                Intent::MY_PROFILE,
                Intent::MY_EXTEND,
                Intent::WHOAMI,
            ];

            return in_array($intent, $memberOnly, true) ? 'need_login' : 'denied_guest';
        }

        if ($role === self::MEMBER) {
            return $intent === Intent::NONE ? 'denied_role' : 'denied_member';
        }

        return 'denied_role';
    }

    /**
     * Force the scope of a request before it reaches the service.
     *
     *  - `my.*` intents always use the session member id;
     *  - PII reveal is removed;
     *  - `limit` is clamped to the configured chat size.
     *
     * @param string $role
     * @param string $intent
     * @param array  $params
     * @param array  $actor
     * @param array  $config
     * @return array
     */
    public static function scope(string $role, string $intent, array $params, array $actor, array $config): array
    {
        $role = Intent::normaliseRole($role);
        $limit = (int)($config['result_limit'] ?? 5);

        if ($limit < 1 || $limit > 20) {
            $limit = 5;
        }

        $params['limit'] = $limit;
        unset($params['reveal']);

        if ($role === self::MEMBER && in_array($intent, [Intent::MY_LOANS, Intent::MY_PROFILE, Intent::MY_EXTEND], true)) {
            $params['member'] = (string)($actor['member_id'] ?? '');
        }

        // A member can never filter somebody else's circulation.
        if ($role === self::MEMBER) {
            unset($params['member_filter']);
        }

        return $params;
    }

    /**
     * Does this role see the staff-only diagnostics block?
     *
     * @param string $role
     * @return bool
     */
    public static function seesDiagnostics(string $role): bool
    {
        return Intent::normaliseRole($role) === self::STAFF;
    }
}
