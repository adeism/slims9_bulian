<?php
/**
 * Who is talking?
 *
 * Two different SLiMS sessions exist and they never mix:
 *
 *   - OPAC (session name `memberArea`): `$_SESSION['mid']`, `m_name`,
 *     `m_email`. A visitor without those keys is a guest.
 *   - Staff area (session name `admin`): `$_SESSION['uid']`, `uname`,
 *     `realname`. Reaching the staff surface requires session_check.inc.php,
 *     so by the time this class runs the user is already authenticated.
 *
 * The role is therefore a property of the server-side session, not of the
 * chat message. That is the whole point: a guest cannot type "saya admin".
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Actor
{
    /**
     * Member area: guest or member.
     *
     * @return array
     */
    public static function fromOpac(): array
    {
        $memberId = isset($_SESSION['mid']) ? (string)$_SESSION['mid'] : '';
        $loggedIn = false;

        if (class_exists('utility') && method_exists('utility', 'isMemberLogin')) {
            $loggedIn = (bool)\utility::isMemberLogin();
        }

        if (!$loggedIn && $memberId !== '' && !empty($_SESSION['m_name'])) {
            $loggedIn = true;
        }

        if (!$loggedIn) {
            return self::anonymous(Policy::GUEST);
        }

        return [
            'role' => Policy::MEMBER,
            'surface' => 'opac',
            'member_id' => $memberId,
            'name' => (string)($_SESSION['m_name'] ?? $memberId),
            'email' => (string)($_SESSION['m_email'] ?? ''),
            'id' => '',
        ];
    }

    /**
     * Staff area. Call only after admin/default/session_check.inc.php.
     *
     * @return array
     */
    public static function fromAdmin(): array
    {
        if (empty($_SESSION['uid']) && empty($_SESSION['uname'])) {
            return self::anonymous(Policy::GUEST);
        }

        return [
            'role' => Policy::STAFF,
            'surface' => 'admin',
            'id' => (string)($_SESSION['uid'] ?? ''),
            'username' => (string)($_SESSION['uname'] ?? ''),
            'name' => (string)($_SESSION['realname'] ?? ($_SESSION['uname'] ?? 'Staff')),
            'member_id' => '',
        ];
    }

    /**
     * @param string $role
     * @return array
     */
    public static function anonymous(string $role = Policy::GUEST): array
    {
        return [
            'role' => Intent::normaliseRole($role),
            'surface' => $role === Policy::STAFF ? 'admin' : 'opac',
            'member_id' => '',
            'id' => '',
            'name' => '',
            'email' => '',
        ];
    }

    /**
     * Build an actor without a session (console commands, self-test).
     *
     * @param string $role
     * @param string $id   staff user id or member id
     * @param string $name
     * @return array
     */
    public static function make(string $role, string $id = '', string $name = ''): array
    {
        $role = Intent::normaliseRole($role);
        $actor = self::anonymous($role);

        if ($role === Policy::MEMBER) {
            $actor['member_id'] = $id;
        } else {
            $actor['id'] = $id;
        }

        $actor['name'] = $name;

        return $actor;
    }

    /**
     * The label shown in the chat header.
     *
     * @param array  $actor
     * @param string $locale
     * @return string
     */
    public static function label(array $actor, string $locale): string
    {
        $role = Intent::normaliseRole((string)($actor['role'] ?? 'guest'));
        $base = Lang::t($locale, 'ui_role_' . $role);
        $name = trim((string)($actor['name'] ?? ''));

        return $name === '' ? $base : $base . ' · ' . $name;
    }
}
