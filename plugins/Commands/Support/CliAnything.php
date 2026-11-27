<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — shared service layer
 *
 * This class is the single place where the agent-facing surface of SLiMS
 * lives. All entry points use it:
 *
 *   1. `tarsius` console commands  -> plugins/Commands/CliAnything*.php
 *   2. HTTP bridge plugin          -> plugins/cli_anything/cli_anything.plugin.php
 *   3. chatbot plugin              -> plugins/chatbot/ (OPAC and staff pages)
 *
 * Design rules (see README "CLI-Anything" section):
 *   - Reads are free (no token needed when called locally).
 *   - Writes are opt-in: config `allow_write`, an explicit `--commit` and,
 *     for multi-record operations, `allow_bulk` + an automatic DB backup.
 *   - Circulation writes go through SLiMS' own `member` / `circulation`
 *     classes, so loan rules, holiday handling, fines and `loan_history`
 *     keep behaving exactly like the admin GUI.
 *   - Every committed write is written to `system_log`.
 *
 * @license GPLv3
 */

namespace Commands\Support;

use SLiMS\DB;
use SLiMS\Log\Factory as Log;

class CliAnything
{
    /**
     * Envelope version. Bump it when the payload shape changes in a
     * backwards incompatible way.
     */
    const SCHEMA_VERSION = '1.0';

    /**
     * Hard ceiling for any list endpoint.
     */
    const MAX_LIMIT = 100;

    /** @var CliAnything|null */
    private static $instance = null;

    /** @var \mysqli */
    private $dbs;

    /** @var array */
    private $options = [];

    /** @var bool */
    private $circulationLoaded = false;

    /** @var array|null actor supplied by an entry point (chat, console) */
    private $actorOverride = null;

    private function __construct()
    {
        $this->dbs = DB::getInstance('mysqli');
        $this->options = self::options();
    }

    public static function instance(): CliAnything
    {
        if (is_null(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    /**
     * Defaults. Anything here can be overridden by
     * `config/cli_anything.php` or by the environment variables below.
     *
     * @return array
     */
    public static function defaults(): array
    {
        return [
            // HTTP bridge master switch
            'enabled' => true,
            // Shared secret for the HTTP bridge. Empty = bridge refuses
            // every request.
            'token' => '',
            // Acting staff account for audit logs
            'user_id' => 1,
            'user_name' => 'CLI-Anything',
            // Write gate
            'allow_write' => false,
            // Bulk gate (more than one record per call)
            'allow_bulk' => false,
            // Mask patron PII in every payload (email, phone, address)
            'redact_pii' => true,
            // Allow HTTP clients to ask for unmasked PII (?reveal=1)
            'allow_pii_reveal' => false,
            // Where automatic pre-bulk backups are written (empty = files/backup)
            'backup_dir' => '',
            // Default page size for list endpoints
            'default_limit' => 20,
        ];
    }

    /**
     * Resolve options: defaults <- config/cli_anything.php <- environment.
     *
     * @return array
     */
    public static function options(): array
    {
        $options = self::defaults();

        if (function_exists('config')) {
            $loaded = config('cli_anything', []);
            if (is_array($loaded)) {
                $options = array_merge($options, $loaded);
            }
        }

        $envMap = [
            'token' => 'SLIMS_CLI_ANYTHING_TOKEN',
            'allow_write' => 'SLIMS_CLI_ANYTHING_ALLOW_WRITE',
            'allow_bulk' => 'SLIMS_CLI_ANYTHING_ALLOW_BULK',
            'backup_dir' => 'SLIMS_CLI_ANYTHING_BACKUP_DIR',
            'user_id' => 'SLIMS_CLI_ANYTHING_USER_ID',
        ];

        foreach ($envMap as $key => $variable) {
            $value = getenv($variable);
            if ($value === false || $value === '') {
                continue;
            }

            if (in_array($key, ['allow_write', 'allow_bulk'], true)) {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $options[$key] = $parsed === null ? (bool)(int)$value : $parsed;
            } elseif ($key === 'user_id') {
                $options[$key] = (int)$value;
            } else {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    public function option(string $key, $default = null)
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    public function writeEnabled(): bool
    {
        return (bool)$this->option('allow_write', false);
    }

    public function bulkEnabled(): bool
    {
        return (bool)$this->option('allow_bulk', false);
    }

    /**
     * Identity used for audit trails.
     *
     * Defaults to the configured service account (`CLI-Anything`), which is
     * what the HTTP bridge and the console use. The chatbot calls
     * `actingAs()` so a write is attributed to the real staff member, or to
     * the member who asked for a self-service renewal.
     *
     * @return array{type:string,id:string,name:string}
     */
    public function actor(): array
    {
        if (!is_null($this->actorOverride)) {
            return $this->actorOverride;
        }

        return [
            'type' => 'staff',
            'id' => (string)(int)$this->option('user_id', 1),
            'name' => (string)$this->option('user_name', 'CLI-Anything'),
        ];
    }

    /**
     * Attribute the next operations to a real person.
     *
     * @param string $type staff|member
     * @param string $id   staff user id or member id
     * @param string $name display name for the log message
     * @return CliAnything
     */
    public function actingAs(string $type, string $id, string $name = ''): CliAnything
    {
        $type = in_array($type, ['staff', 'member'], true) ? $type : 'staff';

        $this->actorOverride = [
            'type' => $type,
            'id' => (string)$id,
            'name' => (string)$name,
        ];

        return $this;
    }

    /**
     * Forget the override and go back to the configured service account.
     *
     * @return CliAnything
     */
    public function resetActor(): CliAnything
    {
        $this->actorOverride = null;

        return $this;
    }

    // -----------------------------------------------------------------
    // Envelope helpers
    // -----------------------------------------------------------------

    public static function success(string $command, array $data, array $meta = []): array
    {
        $options = is_null(self::$instance) ? self::options() : self::$instance->options;

        return [
            'ok' => true,
            'schema' => self::SCHEMA_VERSION,
            'command' => $command,
            'data' => $data,
            'meta' => array_merge([
                'generated_at' => date('c'),
                'write_enabled' => (bool)($options['allow_write'] ?? false),
                'bulk_enabled' => (bool)($options['allow_bulk'] ?? false),
            ], $meta),
        ];
    }

    // -----------------------------------------------------------------
    // Diagnostics
    // -----------------------------------------------------------------

    /**
     * Everything an agent (or a human troubleshooting a deployment) needs
     * to know before it touches this SLiMS instance.
     *
     * @return array
     */
    public function doctor(): array
    {
        $counts = [
            'biblio' => $this->scalar('SELECT COUNT(*) AS total FROM biblio'),
            'item' => $this->scalar('SELECT COUNT(*) AS total FROM item'),
            'member' => $this->scalar('SELECT COUNT(*) AS total FROM member'),
            'loan_active' => $this->scalar('SELECT COUNT(*) AS total FROM loan WHERE is_lent = 1 AND is_return = 0'),
            'loan_overdue' => $this->scalar('SELECT COUNT(*) AS total FROM loan WHERE is_lent = 1 AND is_return = 0 AND due_date < CURDATE()'),
            'fines_outstanding' => (int)$this->scalar('SELECT COALESCE(SUM(debet - credit), 0) AS total FROM fines'),
        ];

        $backupDir = $this->backupDir();

        $checks = [
            [
                'name' => 'database',
                'ok' => true,
                'detail' => (string)$this->dbs->server_info,
            ],
            [
                'name' => 'loan_history_table',
                'ok' => $this->tableExists('loan_history'),
                'detail' => 'Required by SLiMS 9 for circulation audit history.',
            ],
            [
                'name' => 'backup_directory',
                'ok' => is_dir($backupDir) ? is_writable($backupDir) : is_writable(dirname($backupDir)),
                'detail' => $backupDir,
            ],
            [
                'name' => 'tarsius_console',
                'ok' => file_exists(SB . 'tarsius') || file_exists(LIB . 'Cli' . DS . 'tarsius.console'),
                'detail' => 'Console runs via `php index.php <command>`; `php index.php tarsius` creates the shorter `php tarsius <command>` shortcut.',
            ],
        ];

        return [
            'instance' => [
                'slims_name' => defined('SENAYAN_VERSION') ? SENAYAN_VERSION : 'unknown',
                'slims_version' => defined('SENAYAN_VERSION_TAG') ? SENAYAN_VERSION_TAG : 'unknown',
                'schema_version' => self::SCHEMA_VERSION,
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'server' => (string)$this->dbs->server_info,
                'database' => $this->scalar('SELECT DATABASE() AS db'),
                'today' => date('Y-m-d'),
                'timezone' => date_default_timezone_get(),
            ],
            'gates' => [
                'write_enabled' => $this->writeEnabled(),
                'bulk_enabled' => $this->bulkEnabled(),
                'token_configured' => trim((string)$this->option('token', '')) !== '',
                'redact_pii' => (bool)$this->option('redact_pii', true),
                'http_bridge_enabled' => (bool)$this->option('enabled', true),
            ],
            'counts' => $counts,
            'checks' => $checks,
        ];
    }

    // -----------------------------------------------------------------
    // Read: bibliography
    // -----------------------------------------------------------------

    /**
     * @param string $q      Free text (title, ISBN, call number, author)
     * @param int    $limit
     * @param int    $offset
     * @return array
     */
    public function biblioList(string $q = '', int $limit = 20, int $offset = 0): array
    {
        $limit = $this->normalizeLimit($limit);
        $offset = max(0, $offset);

        $where = '';
        $params = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where = 'WHERE (b.title LIKE ? OR b.isbn_issn LIKE ? OR b.call_number LIKE ?
                OR EXISTS (SELECT 1 FROM biblio_author ba
                           INNER JOIN mst_author a ON a.author_id = ba.author_id
                           WHERE ba.biblio_id = b.biblio_id AND a.author_name LIKE ?))';
            $params = [$like, $like, $like, $like];
        }

        $sql = "SELECT b.biblio_id, b.title, b.isbn_issn, b.publish_year, b.call_number, b.classification,
                       b.opac_hide, b.last_update, g.gmd_name,
                       (SELECT COUNT(*) FROM item i WHERE i.biblio_id = b.biblio_id) AS item_count,
                       (SELECT COUNT(*) FROM item i2
                          INNER JOIN loan l ON l.item_code = i2.item_code AND l.is_lent = 1 AND l.is_return = 0
                          WHERE i2.biblio_id = b.biblio_id) AS loan_count,
                       (SELECT GROUP_CONCAT(a2.author_name SEPARATOR ', ')
                          FROM biblio_author ba2
                          INNER JOIN mst_author a2 ON a2.author_id = ba2.author_id
                          WHERE ba2.biblio_id = b.biblio_id) AS authors
                FROM biblio b
                LEFT JOIN mst_gmd g ON g.gmd_id = b.gmd_id
                $where
                ORDER BY b.last_update DESC, b.biblio_id DESC
                LIMIT " . $limit . ' OFFSET ' . $offset;

        $items = [];
        foreach ($this->rows($sql, $params) as $row) {
            $row['biblio_id'] = (int)$row['biblio_id'];
            $row['item_count'] = (int)$row['item_count'];
            $row['loan_count'] = (int)$row['loan_count'];
            $row['available_count'] = $row['item_count'] - $row['loan_count'];
            $items[] = $row;
        }

        $total = (int)$this->scalar('SELECT COUNT(*) AS total FROM biblio b ' . $where, $params);

        return [
            'items' => $items,
            'count' => count($items),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'query' => $q,
        ];
    }

    /**
     * @param int $biblioId
     * @return array
     */
    public function biblioGet(int $biblioId): array
    {
        $biblio = $this->row("SELECT b.*, g.gmd_name, p.publisher_name, pl.place_name, lg.language_name
                FROM biblio b
                LEFT JOIN mst_gmd g ON g.gmd_id = b.gmd_id
                LEFT JOIN mst_publisher p ON p.publisher_id = b.publisher_id
                LEFT JOIN mst_place pl ON pl.place_id = b.publish_place_id
                LEFT JOIN mst_language lg ON lg.language_id = b.language_id
                WHERE b.biblio_id = ?", [$biblioId]);

        if (!$biblio) {
            throw new CliAnythingException('BIBLIO_NOT_FOUND', sprintf('Bibliography #%d not found.', $biblioId));
        }

        $authors = $this->rows("SELECT a.author_id, a.author_name, ba.level
                FROM biblio_author ba
                INNER JOIN mst_author a ON a.author_id = ba.author_id
                WHERE ba.biblio_id = ? ORDER BY ba.level", [$biblioId]);

        $topics = $this->rows("SELECT t.topic_id, t.topic, t.topic_type, bt.level
                FROM biblio_topic bt
                INNER JOIN mst_topic t ON t.topic_id = bt.topic_id
                WHERE bt.biblio_id = ? ORDER BY bt.level", [$biblioId]);

        $items = $this->rows("SELECT i.item_id, i.item_code, i.call_number, i.inventory_code,
                    i.received_date, i.item_status_id, ist.item_status_name, ist.no_loan,
                    ct.coll_type_name, ml.location_name,
                    (SELECT COUNT(*) FROM loan l
                       WHERE l.item_code = i.item_code AND l.is_lent = 1 AND l.is_return = 0) AS on_loan
                FROM item i
                LEFT JOIN mst_item_status ist ON ist.item_status_id = i.item_status_id
                LEFT JOIN mst_coll_type ct ON ct.coll_type_id = i.coll_type_id
                LEFT JOIN mst_location ml ON ml.location_id = i.location_id
                WHERE i.biblio_id = ? ORDER BY i.item_code", [$biblioId]);

        foreach ($items as $index => $item) {
            $items[$index]['item_id'] = (int)$item['item_id'];
            $items[$index]['on_loan'] = (int)$item['on_loan'];
            $items[$index]['loanable'] = (int)$item['on_loan'] === 0 && (int)$item['no_loan'] === 0;
        }

        return [
            'biblio' => $biblio,
            'authors' => $authors,
            'topics' => $topics,
            'items' => $items,
        ];
    }

    // -----------------------------------------------------------------
    // Read: item
    // -----------------------------------------------------------------

    /**
     * @param string $itemCode
     * @return array
     */
    public function itemGet(string $itemCode): array
    {
        $itemCode = trim($itemCode);
        if ($itemCode === '') {
            throw new CliAnythingException('INVALID_ARGUMENT', 'Item code is required.');
        }

        $item = $this->row("SELECT i.item_id, i.item_code, i.biblio_id, i.call_number, i.inventory_code,
                    i.received_date, i.item_status_id, ist.item_status_name, ist.no_loan,
                    ct.coll_type_name, ml.location_name, b.title, b.isbn_issn, b.classification,
                    (SELECT COUNT(*) FROM loan l
                       WHERE l.item_code = i.item_code AND l.is_lent = 1 AND l.is_return = 0) AS on_loan
                FROM item i
                LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
                LEFT JOIN mst_item_status ist ON ist.item_status_id = i.item_status_id
                LEFT JOIN mst_coll_type ct ON ct.coll_type_id = i.coll_type_id
                LEFT JOIN mst_location ml ON ml.location_id = i.location_id
                WHERE i.item_code = ?", [$itemCode]);

        if (!$item) {
            throw new CliAnythingException('ITEM_NOT_FOUND', sprintf('Item %s not found.', $itemCode));
        }

        $item['item_id'] = (int)$item['item_id'];
        $item['biblio_id'] = (int)$item['biblio_id'];
        $item['on_loan'] = (int)$item['on_loan'];
        $item['loanable'] = $item['on_loan'] === 0 && (int)$item['no_loan'] === 0;

        $activeLoan = $this->row("SELECT l.loan_id, l.member_id, m.member_name, l.loan_date, l.due_date,
                    DATEDIFF(CURDATE(), l.due_date) AS overdue_days
                FROM loan l
                LEFT JOIN member m ON m.member_id = l.member_id
                WHERE l.item_code = ? AND l.is_lent = 1 AND l.is_return = 0
                ORDER BY l.loan_id DESC LIMIT 1", [$itemCode]);

        return [
            'item' => $item,
            'active_loan' => $activeLoan ?: null,
        ];
    }

    // -----------------------------------------------------------------
    // Read: member
    // -----------------------------------------------------------------

    /**
     * @param string $memberId
     * @param bool   $redact
     * @return array
     */
    public function memberGet(string $memberId, bool $redact = true): array
    {
        $memberId = trim($memberId);
        if ($memberId === '') {
            throw new CliAnythingException('INVALID_ARGUMENT', 'Member ID is required.');
        }

        $member = $this->row("SELECT m.member_id, m.member_name, m.gender, m.birth_date, m.member_type_id,
                    m.member_address, m.member_mail_address, m.member_email, m.postal_code, m.inst_name,
                    m.member_phone, m.member_fax, m.member_since_date, m.register_date, m.expire_date,
                    m.is_pending, m.member_notes, m.last_login, mt.member_type_name, mt.loan_limit,
                    mt.loan_periode, mt.reborrow_limit, mt.fine_each_day, mt.grace_periode
                FROM member m
                LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
                WHERE m.member_id = ?", [$memberId]);

        if (!$member) {
            throw new CliAnythingException('MEMBER_NOT_FOUND', sprintf('Member %s not found.', $memberId));
        }

        $member['is_pending'] = (int)$member['is_pending'] === 1;
        $member['is_expired'] = $member['expire_date'] !== null && $member['expire_date'] < date('Y-m-d');

        if ($redact) {
            $member = $this->redactMember($member);
        }

        $active = $this->loanList('active', $memberId, self::MAX_LIMIT);
        $overdue = $this->loanList('overdue', $memberId, self::MAX_LIMIT);

        $outstandingFines = (int)$this->scalar('SELECT COALESCE(SUM(debet - credit), 0) AS total FROM fines WHERE member_id = ?', [$memberId]);

        return [
            'member' => $member,
            'summary' => [
                'active_loans' => $active['total'],
                'overdue_loans' => $overdue['total'],
                'outstanding_fines' => $outstandingFines,
            ],
            'loans' => $active['items'],
            'pii_redacted' => $redact,
        ];
    }

    // -----------------------------------------------------------------
    // Read: circulation
    // -----------------------------------------------------------------

    /**
     * @param string $status active|overdue|returned|all
     * @param string $memberId
     * @param int    $limit
     * @return array
     */
    public function loanList(string $status = 'active', string $memberId = '', int $limit = 20): array
    {
        $status = strtolower(trim($status === '' ? 'active' : $status));
        $limit = $this->normalizeLimit($limit);

        $conditions = [
            'active' => 'l.is_lent = 1 AND l.is_return = 0',
            'overdue' => 'l.is_lent = 1 AND l.is_return = 0 AND l.due_date < CURDATE()',
            'returned' => 'l.is_return = 1',
            'all' => '1 = 1',
        ];

        if (!isset($conditions[$status])) {
            throw new CliAnythingException('INVALID_ARGUMENT', 'Unknown loan status: ' . $status . '. Use active, overdue, returned or all.');
        }

        $where = $conditions[$status];
        $params = [];
        if ($memberId !== '') {
            $where .= ' AND l.member_id = ?';
            $params[] = $memberId;
        }

        $sql = "SELECT l.loan_id, l.item_code, l.member_id, m.member_name, b.title, b.biblio_id,
                       l.loan_date, l.due_date, l.return_date, l.renewed, l.loan_rules_id,
                       DATEDIFF(CURDATE(), l.due_date) AS overdue_days
                FROM loan l
                LEFT JOIN member m ON m.member_id = l.member_id
                LEFT JOIN item i ON i.item_code = l.item_code
                LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
                WHERE $where
                ORDER BY l.due_date ASC, l.loan_id ASC
                LIMIT " . $limit;

        $items = [];
        foreach ($this->rows($sql, $params) as $row) {
            $row['loan_id'] = (int)$row['loan_id'];
            $row['biblio_id'] = (int)$row['biblio_id'];
            $row['renewed'] = (int)$row['renewed'];
            $row['overdue_days'] = (int)$row['overdue_days'];
            $items[] = $row;
        }

        $total = (int)$this->scalar("SELECT COUNT(*) AS total FROM loan l WHERE $where", $params);

        return [
            'items' => $items,
            'count' => count($items),
            'total' => $total,
            'status' => $status,
            'member_id' => $memberId === '' ? null : $memberId,
            'limit' => $limit,
        ];
    }

    /**
     * @return array
     */
    public function reportSummary(): array
    {
        return [
            'summary' => [
                'collection' => [
                    'biblio' => $this->scalar('SELECT COUNT(*) AS total FROM biblio'),
                    'items' => $this->scalar('SELECT COUNT(*) AS total FROM item'),
                    'items_available' => $this->scalar('SELECT COUNT(*) AS total FROM item i WHERE NOT EXISTS (
                        SELECT 1 FROM loan l WHERE l.item_code = i.item_code AND l.is_lent = 1 AND l.is_return = 0)'),
                ],
                'members' => [
                    'total' => $this->scalar('SELECT COUNT(*) AS total FROM member'),
                    'pending' => $this->scalar('SELECT COUNT(*) AS total FROM member WHERE is_pending = 1'),
                    'expired' => $this->scalar('SELECT COUNT(*) AS total FROM member WHERE expire_date < CURDATE()'),
                ],
                'circulation' => [
                    'loans_today' => $this->scalar('SELECT COUNT(*) AS total FROM loan WHERE DATE(loan_date) = CURDATE()'),
                    'returns_today' => $this->scalar('SELECT COUNT(*) AS total FROM loan WHERE DATE(return_date) = CURDATE()'),
                    'active' => $this->scalar('SELECT COUNT(*) AS total FROM loan WHERE is_lent = 1 AND is_return = 0'),
                    'overdue' => $this->scalar('SELECT COUNT(*) AS total FROM loan WHERE is_lent = 1 AND is_return = 0 AND due_date < CURDATE()'),
                ],
                'fines' => [
                    'outstanding' => (int)$this->scalar('SELECT COALESCE(SUM(debet - credit), 0) AS total FROM fines'),
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Write: circulation (gated)
    // -----------------------------------------------------------------

    /**
     * Check out one or more items for a member.
     *
     * Without $commit this is a pure planning call: it runs SLiMS' own loan
     * rule engine and reports the due dates that *would* be written.
     *
     * @param string $memberId
     * @param array  $itemCodes
     * @param bool   $commit
     * @return array
     */
    public function loanCheckout(string $memberId, array $itemCodes, bool $commit = false): array
    {
        $memberId = trim($memberId);

        $itemCodes = array_values(array_unique(array_filter(array_map(function ($code) {
            return trim((string)$code);
        }, $itemCodes))));

        if ($memberId === '') {
            throw new CliAnythingException('INVALID_ARGUMENT', 'Member ID is required.');
        }
        if (count($itemCodes) === 0) {
            throw new CliAnythingException('INVALID_ARGUMENT', 'At least one item code is required.');
        }
        if (count($itemCodes) > self::MAX_LIMIT) {
            throw new CliAnythingException('INVALID_ARGUMENT', 'Refusing more than ' . self::MAX_LIMIT . ' items per call.');
        }

        if ($commit) {
            $this->assertWriteAllowed('loan.checkout');
            if (count($itemCodes) > 1) {
                $this->assertBulkAllowed('loan.checkout');
            }
        }

        $this->loadCirculation();

        return $this->withSession(function () use ($memberId, $itemCodes, $commit) {
            $circ = new \circulation($this->dbs, $this->dbs->escape_string($memberId));

            if (!$circ->valid()) {
                throw new CliAnythingException('MEMBER_NOT_FOUND', sprintf('Member %s not found.', $memberId));
            }
            if ($circ->isExpired()) {
                throw new CliAnythingException('MEMBER_EXPIRED', sprintf('Membership %s is expired.', $memberId));
            }
            if ($circ->isPending()) {
                throw new CliAnythingException('MEMBER_PENDING', sprintf('Membership %s is pending.', $memberId));
            }

            $results = [];
            $allReady = true;

            foreach ($itemCodes as $code) {
                $escapedCode = $this->dbs->escape_string($code);
                $status = (int)$circ->addLoanSession($escapedCode);
                $entry = [
                    'item_code' => $code,
                    'status_code' => $status,
                    'status' => $this->loanStatusLabel($status),
                    'ready' => false,
                ];

                if ($status === ITEM_SESSION_ADDED) {
                    $planned = isset($_SESSION['temp_loan'][$escapedCode]) ? $_SESSION['temp_loan'][$escapedCode] : null;
                    if ($planned) {
                        $entry['title'] = $planned['title'];
                        $entry['loan_date'] = $planned['loan_date'];
                        $entry['due_date'] = $planned['due_date'];
                        $entry['ready'] = true;
                    }
                } else {
                    $allReady = false;
                    $entry['title'] = (string)$this->scalar('SELECT b.title FROM item i LEFT JOIN biblio b ON b.biblio_id = i.biblio_id WHERE i.item_code = ?', [$code]);
                }

                $results[] = $entry;
            }

            $base = [
                'operation' => 'loan.checkout',
                'member_id' => $memberId,
                'member_name' => $circ->member_name,
                'items' => $results,
                'ready' => $allReady,
                'committed' => false,
                'backup' => null,
                'receipt' => [],
            ];

            if (!$commit) {
                // planning only: throw the temporary loan session away
                $_SESSION['temp_loan'] = [];

                return array_merge($base, ['dry_run' => true]);
            }

            if (!$allReady) {
                $_SESSION['temp_loan'] = [];
                throw new CliAnythingException(
                    'LOAN_REJECTED',
                    'One or more items cannot be loaned; nothing was written.',
                    ['items' => $results]
                );
            }

            // Bulk write -> snapshot before touching the database
            $backup = count($itemCodes) > 1 ? $this->backup('bulk-checkout') : null;

            $finish = $circ->finishLoanSession();
            if ($finish !== TRANS_FLUSH_SUCCESS) {
                throw new CliAnythingException(
                    'TRANSACTION_FAILED',
                    $circ->error !== '' ? $circ->error : 'SLiMS failed to commit the loan transaction.',
                    ['items' => $results]
                );
            }

            $receipt = isset($_SESSION['receipt_record']['loan']) ? $_SESSION['receipt_record']['loan'] : [];

            $this->audit('Checkout', sprintf(
                'CLI-Anything checkout for member %s (%s): items %s',
                $memberId,
                $circ->member_name,
                implode(', ', $itemCodes)
            ));

            return array_merge($base, [
                'dry_run' => false,
                'committed' => true,
                'backup' => $backup,
                'receipt' => $receipt,
            ]);
        }, $memberId);
    }

    /**
     * Return an item. Identify the loan by loan id or item code.
     *
     * Without $commit this is a pure planning call: it asks SLiMS'
     * `countOverdueValue()` how many days late the item is and which fine
     * *would* be created, but writes nothing.
     *
     * @param int|null    $loanId
     * @param string|null $itemCode
     * @param bool        $commit
     * @return array
     */
    public function loanCheckin(?int $loanId, ?string $itemCode, bool $commit = false): array
    {
        if ($commit) {
            $this->assertWriteAllowed('loan.checkin');
        }

        $this->loadCirculation();

        $loan = $this->findActiveLoan($loanId, $itemCode);

        return $this->withSession(function () use ($loan, $commit) {
            $circ = new \circulation($this->dbs, $this->dbs->escape_string($loan['member_id']));
            $returnDate = date('Y-m-d');
            $overdue = $circ->countOverdueValue((int)$loan['loan_id'], $returnDate);

            $plan = [
                'loan_id' => (int)$loan['loan_id'],
                'item_code' => $loan['item_code'],
                'title' => $loan['title'],
                'member_id' => $loan['member_id'],
                'member_name' => $loan['member_name'],
                'loan_date' => $loan['loan_date'],
                'due_date' => $loan['due_date'],
                'return_date' => $returnDate,
                'overdue_days' => $overdue ? (int)$overdue['days'] : 0,
                'fine_amount' => $overdue ? (int)$overdue['value'] : 0,
            ];

            $base = [
                'operation' => 'loan.checkin',
                'dry_run' => !$commit,
                'committed' => false,
                'plan' => $plan,
                'reserved_for_other_member' => false,
                'receipt' => [],
            ];

            if (!$commit) {
                return $base;
            }

            $result = $circ->returnItem((int)$loan['loan_id']);
            $receipt = isset($_SESSION['receipt_record']['return']) ? $_SESSION['receipt_record']['return'] : [];

            $this->audit('Checkin', sprintf(
                'CLI-Anything return loan #%d (%s) for member %s, overdue %d day(s), fine %d, return date %s',
                (int)$loan['loan_id'],
                $loan['item_code'],
                $loan['member_id'],
                $plan['overdue_days'],
                $plan['fine_amount'],
                $returnDate
            ));

            return array_merge($base, [
                'dry_run' => false,
                'committed' => true,
                'reserved_for_other_member' => $result === ITEM_RESERVED,
                'receipt' => $receipt,
            ]);
        }, $loan['member_id']);
    }

    /**
     * Renew a loan.
     *
     * The dry-run explains whether SLiMS will allow the renewal (reborrow
     * limit, reservation by another member) and what the new due date
     * would be, without writing anything.
     *
     * @param int|null    $loanId
     * @param bool        $commit
     * @param string|null $itemCode optional alternative to the loan id
     * @return array
     */
    public function loanExtend(?int $loanId, bool $commit = false, ?string $itemCode = null): array
    {
        if ($commit) {
            $this->assertWriteAllowed('loan.extend');
        }

        $this->loadCirculation();

        $loan = $this->findActiveLoan($loanId, $itemCode);

        return $this->withSession(function () use ($loan, $commit) {
            $circ = new \circulation($this->dbs, $this->dbs->escape_string($loan['member_id']));

            $reborrowLimit = $this->reborrowLimitFor($loan, $circ);
            $renewed = (int)$loan['renewed'];
            $reserved = $this->isReservedForOther($loan);

            // SLiMS disables the renew button when renewed >= reborrow_limit
            // (see admin/modules/circulation/loan_list.php), so 0 means
            // "renewal not allowed" rather than "unlimited".
            $blocked = [];
            if ($renewed >= $reborrowLimit) {
                $blocked[] = [
                    'code' => 'RENEW_LIMIT_REACHED',
                    'message' => sprintf(
                        'Loan #%d already renewed %d time(s), reborrow limit is %d.',
                        (int)$loan['loan_id'],
                        $renewed,
                        $reborrowLimit
                    ),
                ];
            }
            if ($reserved) {
                $blocked[] = [
                    'code' => 'ITEM_RESERVED',
                    'message' => sprintf('Item %s is reserved by another member.', $loan['item_code']),
                ];
            }

            $plan = [
                'loan_id' => (int)$loan['loan_id'],
                'item_code' => $loan['item_code'],
                'member_id' => $loan['member_id'],
                'member_name' => $loan['member_name'],
                'title' => $loan['title'],
                'current_due_date' => $loan['due_date'],
                'renewed' => $renewed,
                'reborrow_limit' => $reborrowLimit,
                'reserved_for_other_member' => $reserved,
                'projected_due_date' => $this->estimateDueDate($loan, $circ),
                'renewable' => empty($blocked),
                'blocked' => $blocked,
            ];

            if (!$commit) {
                return [
                    'operation' => 'loan.extend',
                    'dry_run' => true,
                    'committed' => false,
                    'plan' => $plan,
                ];
            }

            if (!empty($blocked)) {
                throw new CliAnythingException($blocked[0]['code'], $blocked[0]['message'], ['plan' => $plan]);
            }

            $result = $circ->extendItemLoan((int)$loan['loan_id']);
            if ($result === ITEM_RESERVED) {
                throw new CliAnythingException(
                    'ITEM_RESERVED',
                    sprintf('Item %s is reserved by another member; renewal refused.', $loan['item_code']),
                    ['plan' => $plan]
                );
            }
            if ($result !== true) {
                throw new CliAnythingException('TRANSACTION_FAILED', 'SLiMS failed to renew the loan.', ['plan' => $plan]);
            }

            $this->audit('Extend', sprintf(
                'CLI-Anything renew loan #%d (%s) for member %s, new due date %s',
                (int)$loan['loan_id'],
                $loan['item_code'],
                $loan['member_id'],
                $plan['projected_due_date']
            ));

            return [
                'operation' => 'loan.extend',
                'dry_run' => false,
                'committed' => true,
                'plan' => $plan,
            ];
        }, $loan['member_id']);
    }

    // -----------------------------------------------------------------
    // Backup
    // -----------------------------------------------------------------

    /**
     * Dump the database before a destructive/bulk operation.
     *
     * @param string $reason
     * @return string absolute path of the dump
     */
    public function backup(string $reason = 'manual'): string
    {
        $directory = $this->ensureBackupDir();
        $filename = 'cli-anything-' . preg_replace('/[^a-z0-9\-]+/i', '-', $reason) . '-' . date('YmdHis') . '.sql';
        $path = $directory . $filename;

        DB::getInstance();

        try {
            DB::backup()->start($path);
        } catch (\Throwable $e) {
            throw new CliAnythingException('BACKUP_FAILED', 'Database backup failed: ' . $e->getMessage());
        }

        if (!file_exists($path) || filesize($path) === 0) {
            throw new CliAnythingException('BACKUP_FAILED', 'Database backup was not created: ' . $path);
        }

        try {
            DB::query('INSERT INTO backup_log SET user_id = ?, backup_time = ?, backup_file = ?', [
                $this->actor()['id'],
                date('Y-m-d H:i:s'),
                $path,
            ])->isAffected();
        } catch (\Throwable $e) {
            // logging is best effort; the dump itself is what matters
        }

        return $path;
    }

    private function ensureBackupDir(): string
    {
        $directory = $this->backupDir();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
            throw new CliAnythingException('BACKUP_FAILED', 'Cannot create backup directory: ' . $directory);
        }
        if (!is_writable($directory)) {
            throw new CliAnythingException('BACKUP_FAILED', 'Backup directory is not writable: ' . $directory);
        }

        return $directory;
    }

    public function backupDir(): string
    {
        $configured = trim((string)$this->option('backup_dir', ''));
        $directory = $configured !== '' ? $configured : SB . 'files' . DS . 'backup';
        $directory = rtrim($directory, DS) . DS;

        return $directory;
    }

    // -----------------------------------------------------------------
    // Gates
    // -----------------------------------------------------------------

    private function assertWriteAllowed(string $operation): void
    {
        if (!$this->writeEnabled()) {
            throw new CliAnythingException(
                'WRITE_DISABLED',
                sprintf(
                    'Write operations are disabled (%s). Enable allow_write in config/cli_anything.php or set SLIMS_CLI_ANYTHING_ALLOW_WRITE=1.',
                    $operation
                )
            );
        }
    }

    private function assertBulkAllowed(string $operation): void
    {
        if (!$this->bulkEnabled()) {
            throw new CliAnythingException(
                'BULK_DISABLED',
                sprintf(
                    'Bulk operations are disabled (%s). Enable allow_bulk in config/cli_anything.php or set SLIMS_CLI_ANYTHING_ALLOW_BULK=1.',
                    $operation
                )
            );
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Load SLiMS' circulation classes so we never re-implement loan rules.
     *
     * @return void
     */
    private function loadCirculation(): void
    {
        if ($this->circulationLoaded) {
            return;
        }

        require_once SIMBIO . 'simbio_DB' . DS . 'simbio_dbop.inc.php';
        require_once SIMBIO . 'simbio_UTILS' . DS . 'simbio_date.inc.php';
        require_once LIB . 'circulation_api.inc.php';
        require_once MDLBS . 'membership' . DS . 'member_base_lib.inc.php';
        require_once MDLBS . 'circulation' . DS . 'circulation_base_lib.inc.php';

        $this->circulationLoaded = true;
    }

    /**
     * Run a callback with a clean circulation session, then restore the
     * previous session state (important for the HTTP bridge, which shares
     * the OPAC/admin session).
     *
     * @param callable $callback
     * @return mixed
     */
    private function withSession(callable $callback, ?string $memberId = null)
    {
        $snapshot = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [];
        $actor = $this->actor();

        // SLiMS' circulation classes attribute their own log lines to
        // $_SESSION['uid'], which must stay a staff account even when the
        // request came from a member (chat self-service renewal).
        $_SESSION['uid'] = ($actor['type'] ?? 'staff') === 'member'
            ? (int)$this->option('user_id', 1)
            : $actor['id'];
        $_SESSION['realname'] = $actor['name'];
        if ($memberId !== null && $memberId !== '') {
            $_SESSION['memberID'] = $memberId;
        }
        $_SESSION['temp_loan'] = [];
        $_SESSION['reborrowed'] = [];
        $_SESSION['receipt_record'] = [];

        try {
            return $callback();
        } finally {
            $_SESSION = $snapshot;
        }
    }

    /**
     * @param int|null    $loanId
     * @param string|null $itemCode
     * @return array
     */
    private function findActiveLoan(?int $loanId, ?string $itemCode): array
    {
        if ($loanId !== null && $loanId > 0) {
            $loan = $this->row($this->activeLoanSql() . ' AND l.loan_id = ?', [$loanId]);
        } elseif ($itemCode !== null && trim($itemCode) !== '') {
            $loan = $this->row($this->activeLoanSql() . ' AND l.item_code = ?', [trim($itemCode)]);
        } else {
            throw new CliAnythingException('INVALID_ARGUMENT', 'Either loan id or item code is required.');
        }

        if (!$loan) {
            throw new CliAnythingException('LOAN_NOT_FOUND', 'No active loan matches the given identifier.');
        }

        $loan['loan_id'] = (int)$loan['loan_id'];

        return $loan;
    }

    private function activeLoanSql(): string
    {
        return "SELECT l.loan_id, l.item_code, l.member_id, l.loan_date, l.due_date, l.renewed,
                       l.loan_rules_id, m.member_name, b.title
                FROM loan l
                LEFT JOIN member m ON m.member_id = l.member_id
                LEFT JOIN item i ON i.item_code = l.item_code
                LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
                WHERE l.is_lent = 1 AND l.is_return = 0";
    }

    /**
     * Reborrow limit for a loan: loan rules first, member type as fallback.
     *
     * @param array        $loan
     * @param \circulation $circ
     * @return int
     */
    private function reborrowLimitFor(array $loan, $circ): int
    {
        if (!empty($loan['loan_rules_id'])) {
            $rules = $this->row('SELECT reborrow_limit FROM mst_loan_rules WHERE loan_rules_id = ?', [(int)$loan['loan_rules_id']]);
            if ($rules) {
                return (int)$rules['reborrow_limit'];
            }
        }

        $props = $circ->getMemberTypeProp();

        return (int)($props['reborrow_limit'] ?? 0);
    }

    private function isReservedForOther(array $loan): bool
    {
        $count = (int)$this->scalar('SELECT COUNT(*) AS total FROM reserve WHERE item_code = ? AND member_id <> ?', [
            $loan['item_code'],
            $loan['member_id'],
        ]);

        return $count > 0;
    }

    /**
     * Project the next due date without writing anything.
     *
     * Mirrors `circulation::extendItemLoan()`: loan rules first, then the
     * member type period, clamped by holidays and the membership expiry.
     *
     * @param array        $loan
     * @param \circulation $circ
     * @return string
     */
    private function estimateDueDate(array $loan, $circ): string
    {
        $period = null;

        if (!empty($loan['loan_rules_id'])) {
            $rules = $this->row('SELECT loan_periode FROM mst_loan_rules WHERE loan_rules_id = ?', [(int)$loan['loan_rules_id']]);
            if ($rules) {
                $period = (int)$rules['loan_periode'];
            }
        }

        if ($period === null) {
            $props = $circ->getMemberTypeProp();
            $period = (int)($props['loan_periode'] ?? 0);
        }

        $today = date('Y-m-d');
        $due = \simbio_date::getNextDate($period, $today);
        $due = \simbio_date::getNextDateNotHoliday($due, $circ->holiday_dayname, $circ->holiday_date);

        if (!empty($circ->expire_date) && \simbio_date::compareDates($due, $circ->expire_date) != $circ->expire_date) {
            $due = $circ->expire_date;
        }

        return $due;
    }

    private function loanStatusLabel(int $status): string
    {
        $labels = [
            0 => 'unknown',
            LOAN_LIMIT_REACHED => 'loan limit reached',
            ITEM_NOT_FOUND => 'item not found',
            ITEM_SESSION_ADDED => 'ready',
            ITEM_UNAVAILABLE => 'already on loan',
            TRANS_FLUSH_ERROR => 'transaction failed',
            TRANS_FLUSH_SUCCESS => 'committed',
            LOAN_NOT_PERMITTED => 'loan not permitted (membership expired)',
            LOAN_NOT_PERMITTED_PENDING => 'loan not permitted (membership pending)',
            ITEM_LOAN_FORBID => 'item is not loanable',
            ITEM_RESERVED => 'reserved by another member',
        ];

        return $labels[$status] ?? 'unknown';
    }

    private function audit(string $action, string $message): bool
    {
        try {
            $actor = $this->actor();

            Log::write(
                (string)($actor['type'] ?? 'staff'),
                (string)$actor['id'],
                'CLI-Anything',
                $message,
                'CLI-Anything',
                $action
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function redactMember(array $member): array
    {
        foreach (['member_email', 'member_phone', 'member_fax', 'member_address', 'member_mail_address'] as $field) {
            if (!empty($member[$field])) {
                $member[$field] = '***redacted***';
            }
        }

        return $member;
    }

    private function normalizeLimit(int $limit): int
    {
        $default = (int)$this->option('default_limit', 20);
        if ($limit <= 0) {
            $limit = $default;
        }

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    private function tableExists(string $table): bool
    {
        $count = (int)$this->scalar(
            'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return $count > 0;
    }

    /**
     * @param string $sql
     * @param array  $params
     * @return array
     */
    private function rows(string $sql, array $params = []): array
    {
        $query = DB::query($sql, $params);
        $data = $query->toArray();

        if ($query->getError() !== '' && $query->getError() !== null) {
            throw new CliAnythingException('QUERY_FAILED', $query->getError());
        }

        return $data;
    }

    /**
     * @param string $sql
     * @param array  $params
     * @return array|null
     */
    private function row(string $sql, array $params = [])
    {
        $rows = $this->rows($sql, $params);

        return count($rows) > 0 ? $rows[0] : null;
    }

    /**
     * @param string $sql
     * @param array  $params
     * @return mixed
     */
    private function scalar(string $sql, array $params = [])
    {
        $row = $this->row($sql, $params);
        if (!$row) {
            return 0;
        }

        return array_values($row)[0];
    }
}
