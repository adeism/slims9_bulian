<?php
/**
 * Chat engine
 *
 * Turns a message into an intent (Intent), checks the role (Policy), calls
 * the CLI-Anything service, and renders the answer as text + blocks that
 * the browser paints with `textContent` (so a title from the database can
 * never inject markup).
 *
 * The important property: this class owns no SQL and no business rule. It
 * is a mouth in front of `Commands\Support\CliAnything`, the same service
 * behind `php index.php clianything:*` and the HTTP bridge. Gates, audit,
 * PII masking and loan rules therefore behave identically in all three.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Engine
{
    /** @var array */
    private $config;

    /** @var string */
    private $role;

    /** @var array */
    private $actor;

    /** @var string */
    private $locale;

    /** @var object|null */
    private $service;

    /** @var int */
    private $ttl;

    /**
     * @param array       $actor   from Actor::fromOpac()/fromAdmin()
     * @param array       $config  defaults to Config::all()
     * @param object|null $service anything with the CliAnything method set
     */
    public function __construct(array $actor, array $config = [], $service = null)
    {
        $this->actor = array_merge(Actor::anonymous(), $actor);
        $this->role = Intent::normaliseRole((string)$this->actor['role']);
        $this->config = $config === [] ? Config::all() : array_merge(Config::defaults(), $config);
        $this->locale = Lang::locale($this->config);
        $this->service = $service;
        $this->ttl = max(30, min(900, (int)($this->config['confirm_ttl'] ?? 180)));
    }

    /**
     * Handle one message (or one confirmation).
     *
     * @param string      $message
     * @param string|null $confirmToken token returned by a previous reply
     * @return array
     */
    public function handle(string $message, ?string $confirmToken = null): array
    {
        $message = trim($message);
        $max = max(40, (int)($this->config['max_message_length'] ?? 500));

        if (Str::length($message) > $max) {
            return $this->plain(Lang::t($this->locale, 'too_long', ['max' => $max]), 'warn');
        }

        if (!RateLimit::hit(RateLimit::MESSAGES, (int)($this->config['rate_limit_per_minute'] ?? 20), 60)) {
            return $this->plain(Lang::t($this->locale, 'rate_limited'), 'warn');
        }

        if (!is_null($confirmToken) && $confirmToken !== '') {
            return $this->handleConfirm($confirmToken);
        }

        $intent = Intent::resolve($message, $this->role);

        if (!Policy::allows($this->role, $intent['name'])) {
            return $this->denied($intent);
        }

        return $this->dispatch($intent);
    }

    /**
     * @return string
     */
    public function role(): string
    {
        return $this->role;
    }

    /**
     * @return string
     */
    public function locale(): string
    {
        return $this->locale;
    }

    // -----------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------

    /**
     * @param array $intent
     * @return array
     */
    private function dispatch(array $intent): array
    {
        switch ($intent['name']) {
            case Intent::GREETING:
                return $this->greeting();

            case Intent::THANKS:
                return $this->plain(Lang::t($this->locale, 'greet_' . $this->role, $this->greetingPlaceholders()));

            case Intent::HELP:
                return $this->help();

            case Intent::WHOAMI:
                return $this->whoami();

            case Intent::NONE:
                return $this->notUnderstood();
        }

        $params = Policy::scope($this->role, $intent['name'], (array)$intent['params'], $this->actor, $this->config);

        if (Policy::isWrite($intent['name'])) {
            return $this->writeFlow($intent['name'], $params);
        }

        return $this->readFlow($intent['name'], $params, $intent);
    }

    /**
     * @return array
     */
    private function greeting(): array
    {
        $text = Lang::t($this->locale, 'greet_' . $this->role, $this->greetingPlaceholders());

        return $this->plain($text);
    }

    /**
     * @return array
     */
    private function help(): array
    {
        $blocks = [
            $this->block('text', ['text' => Lang::t($this->locale, 'help')]),
            $this->block('list', [
                'title' => Lang::t($this->locale, 'ui_chips'),
                'items' => array_map(function ($line) {
                    return ['title' => $line];
                }, Intent::chips($this->role, $this->locale)),
            ]),
        ];

        if ($this->role === Policy::GUEST) {
            $blocks[] = $this->loginLink();
        }

        return $this->respond(Lang::t($this->locale, 'help'), $blocks);
    }

    /**
     * @return array
     */
    private function whoami(): array
    {
        if ($this->role === Policy::MEMBER) {
            return $this->readFlow(Intent::MY_PROFILE, ['member' => (string)$this->actor['member_id'], 'limit' => 5], [
                'name' => Intent::MY_PROFILE,
                'confidence' => 1.0,
            ]);
        }

        if ($this->role === Policy::STAFF) {
            $items = [
                ['label' => Lang::t($this->locale, 'ui_role_staff'), 'value' => (string)($this->actor['name'] ?: $this->actor['id'])],
                ['label' => 'uid', 'value' => (string)$this->actor['id']],
            ];

            return $this->respond(Lang::t($this->locale, 'ui_role_staff'), [
                $this->block('facts', ['items' => $items]),
                $this->block('notice', ['tone' => 'info', 'text' => Lang::t($this->locale, 'ui_powered')]),
            ]);
        }

        return $this->denied(['name' => Intent::WHOAMI, 'params' => [], 'confidence' => 1.0]);
    }

    /**
     * @return array
     */
    private function notUnderstood(): array
    {
        return $this->plain(Lang::t($this->locale, 'not_understood', [
            'examples' => Lang::t($this->locale, 'examples_' . $this->role),
        ]), 'info');
    }

    /**
     * @param array $intent
     * @return array
     */
    private function denied(array $intent): array
    {
        $reason = Policy::denialReason($this->role, $intent['name']);
        $blocks = [];

        if ($reason === 'need_login') {
            $blocks[] = $this->loginLink();
        }

        return $this->plain(Lang::t($this->locale, $reason), $reason === 'need_login' ? 'info' : 'warn', $blocks);
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /**
     * @param string     $name
     * @param array      $params
     * @param array|null $intent
     * @return array
     */
    private function readFlow(string $name, array $params, ?array $intent = null): array
    {
        $missing = $this->missingParameter($name, $params);

        if (!is_null($missing)) {
            return $this->plain(Lang::t($this->locale, $missing), 'info');
        }

        $result = $this->runIntent($name, $params, false);

        if (!$result['ok']) {
            return $this->failure($result['error']);
        }

        return $this->renderRead($name, $result['data'], $params, $intent);
    }

    /**
     * @param string $name
     * @param array  $params
     * @return string|null language key of the question to ask
     */
    private function missingParameter(string $name, array $params)
    {
        switch ($name) {
            case Intent::BIBLIO_GET:
                return empty($params['id']) ? 'ask_biblio' : null;

            case Intent::ITEM_GET:
                return empty($params['code']) ? 'ask_item' : null;

            case Intent::MEMBER_GET:
                return empty($params['member']) ? 'ask_member' : null;
        }

        return null;
    }

    /**
     * @param string     $name
     * @param array      $data
     * @param array      $params
     * @param array|null $intent
     * @return array
     */
    private function renderRead(string $name, array $data, array $params, ?array $intent = null): array
    {
        $meta = [
            'intent' => $name,
            'confidence' => $intent['confidence'] ?? 1.0,
        ];

        if ((bool)($this->config['log_queries'] ?? false) && isset($params['q'])) {
            $meta['query'] = (string)$params['q'];
        }

        switch ($name) {
            case Intent::BIBLIO_LIST:
                return $this->renderBiblioList($data, $params, $meta);

            case Intent::BIBLIO_GET:
                return $this->renderBiblioDetail($data, $meta);

            case Intent::ITEM_GET:
                return $this->renderItem($data, $meta);

            case Intent::LOAN_LIST:
            case Intent::MY_LOANS:
                return $this->renderLoans($data, $name === Intent::MY_LOANS, $meta);

            case Intent::MEMBER_GET:
            case Intent::MY_PROFILE:
                return $this->renderMember($data, $meta);

            case Intent::REPORT_SUMMARY:
                return $this->renderReport($data, $meta);

            case Intent::DOCTOR:
                return $this->renderDoctor($data, $meta);
        }

        return $this->respond(Lang::t($this->locale, 'help'), []);
    }

    /**
     * @param array $data
     * @param array $params
     * @param array $meta
     * @return array
     */
    private function renderBiblioList(array $data, array $params, array $meta): array
    {
        $rows = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $query = trim((string)($params['q'] ?? ''));

        if (empty($rows)) {
            return $this->respond(
                Lang::t($this->locale, 'no_results', ['query' => $query === '' ? '-' : $query]),
                [$this->block('notice', ['tone' => 'info', 'text' => Lang::t($this->locale, 'examples_' . $this->role)])],
                $meta
            );
        }

        $items = [];
        foreach ($rows as $row) {
            $metaLine = array_filter([
                (string)($row['authors'] ?? ''),
                (string)($row['publish_year'] ?? ''),
                (string)($row['call_number'] ?? ''),
            ], function ($value) {
                return trim($value) !== '';
            });

            $available = (int)($row['available_count'] ?? 0);
            $total = (int)($row['item_count'] ?? 0);

            $items[] = [
                'title' => (string)($row['title'] ?? '-'),
                'meta' => implode(' · ', $metaLine),
                'badge' => $available . '/' . $total . ' ' . Lang::t($this->locale, 'label_available'),
                'tone' => $available > 0 ? 'ok' : 'muted',
                'href' => $this->opacBiblioLink((int)($row['biblio_id'] ?? 0)),
            ];
        }

        $text = $query === ''
            ? Lang::t($this->locale, 'biblio_header_empty')
            : Lang::t($this->locale, 'biblio_header', [
                'count' => (int)($data['count'] ?? count($rows)),
                'total' => (int)($data['total'] ?? count($rows)),
                'query' => $query,
            ]);

        return $this->respond($text, [['type' => 'list', 'items' => $items]], $meta);
    }

    /**
     * @param array $data
     * @param array $meta
     * @return array
     */
    private function renderBiblioDetail(array $data, array $meta): array
    {
        $biblio = isset($data['biblio']) && is_array($data['biblio']) ? $data['biblio'] : [];
        $authors = [];
        foreach ((array)($data['authors'] ?? []) as $author) {
            if (!empty($author['author_name'])) {
                $authors[] = (string)$author['author_name'];
            }
        }

        $facts = [
            ['label' => Lang::t($this->locale, 'label_title'), 'value' => (string)($biblio['title'] ?? '-')],
            ['label' => Lang::t($this->locale, 'label_author'), 'value' => implode(', ', $authors)],
            ['label' => Lang::t($this->locale, 'label_call_number'), 'value' => (string)($biblio['call_number'] ?? '-')],
            ['label' => Lang::t($this->locale, 'label_isbn'), 'value' => (string)($biblio['isbn_issn'] ?? '-')],
        ];

        $publisher = trim(implode(' · ', array_filter([
            (string)($biblio['publisher_name'] ?? ''),
            (string)($biblio['publish_year'] ?? ''),
        ])));

        if ($publisher !== '') {
            $facts[] = ['label' => Lang::t($this->locale, 'label_year'), 'value' => $publisher];
        }

        $items = [];
        foreach ((array)($data['items'] ?? []) as $copy) {
            $loanable = !empty($copy['loanable']);
            $items[] = [
                'title' => (string)($copy['item_code'] ?? '-'),
                'meta' => trim(implode(' · ', array_filter([
                    (string)($copy['call_number'] ?? ''),
                    (string)($copy['location_name'] ?? ''),
                    (string)($copy['item_status_name'] ?? ''),
                ]))),
                'badge' => Lang::t($this->locale, $loanable ? 'item_available' : 'item_on_loan'),
                'tone' => $loanable ? 'ok' : 'warn',
            ];
        }

        $blocks = [$this->block('facts', ['items' => $facts])];

        if (!empty($items)) {
            $blocks[] = ['type' => 'list', 'title' => Lang::t($this->locale, 'label_items'), 'items' => $items];
        }

        return $this->respond(
            Lang::t($this->locale, 'biblio_detail', ['id' => (int)($biblio['biblio_id'] ?? 0)]),
            $blocks,
            $meta
        );
    }

    /**
     * @param array $data
     * @param array $meta
     * @return array
     */
    private function renderItem(array $data, array $meta): array
    {
        $item = isset($data['item']) && is_array($data['item']) ? $data['item'] : [];
        $loan = is_array($data['active_loan'] ?? null) ? $data['active_loan'] : null;

        $facts = [
            ['label' => Lang::t($this->locale, 'label_title'), 'value' => (string)($item['title'] ?? '-')],
            ['label' => Lang::t($this->locale, 'label_call_number'), 'value' => (string)($item['call_number'] ?? '-')],
            ['label' => 'item', 'value' => (string)($item['item_code'] ?? '-')],
        ];

        if (!empty($item['item_status_name'])) {
            $facts[] = ['label' => Lang::t($this->locale, 'label_status'), 'value' => (string)$item['item_status_name']];
        }

        $blocks = [$this->block('facts', ['items' => $facts])];

        if (!empty($item['loanable'])) {
            $blocks[] = $this->block('notice', [
                'tone' => 'success',
                'text' => Lang::t($this->locale, 'item_available'),
            ]);
        } elseif ($loan) {
            $text = Lang::t($this->locale, 'item_on_loan', ['due' => $this->date($loan['due_date'] ?? '')]);
            if ($this->role === Policy::STAFF && !empty($loan['member_name'])) {
                $text .= ' · ' . $loan['member_name'];
            }
            $blocks[] = $this->block('notice', ['tone' => 'warn', 'text' => $text]);
        } else {
            $blocks[] = $this->block('notice', [
                'tone' => 'warn',
                'text' => Lang::t($this->locale, 'item_not_loanable'),
            ]);
        }

        return $this->respond(
            Lang::t($this->locale, 'item_header', ['code' => (string)($item['item_code'] ?? '')]),
            $blocks,
            $meta
        );
    }

    /**
     * @param array $data
     * @param bool  $self
     * @param array $meta
     * @return array
     */
    private function renderLoans(array $data, bool $self, array $meta): array
    {
        $rows = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $total = (int)($data['total'] ?? count($rows));

        if ($total === 0) {
            return $this->respond(Lang::t($this->locale, 'loans_empty'), [], $meta);
        }

        $items = [];
        foreach ($rows as $row) {
            $badge = '';
            $tone = 'muted';
            $days = (int)($row['overdue_days'] ?? 0);

            if ($days > 0) {
                $badge = Lang::t($this->locale, 'loans_overdue_badge', ['days' => $days]);
                $tone = 'warn';
            } else {
                $badge = Lang::t($this->locale, 'label_due') . ' ' . $this->date($row['due_date'] ?? '');
                $tone = 'ok';
            }

            $metaLine = array_filter([
                (string)($row['item_code'] ?? ''),
                !$self && !empty($row['member_name']) ? (string)$row['member_name'] : '',
                (string)($row['loan_date'] ?? '') !== '' ? Lang::t($this->locale, 'label_loan_date') . ' ' . $this->date($row['loan_date']) : '',
            ], function ($value) {
                return trim($value) !== '';
            });

            $items[] = [
                'title' => (string)($row['title'] ?? $row['item_code'] ?? '-'),
                'meta' => implode(' · ', $metaLine),
                'badge' => $badge,
                'tone' => $tone,
            ];
        }

        $status = (string)($data['status'] ?? 'active');
        $headerKey = $status === 'overdue'
            ? 'loans_overdue_header'
            : ($status === 'returned' ? 'loans_returned_header' : 'loans_header');

        return $this->respond(
            Lang::t($this->locale, $headerKey, ['count' => $total]),
            [['type' => 'list', 'items' => $items]],
            $meta
        );
    }

    /**
     * @param array $data
     * @param array $meta
     * @return array
     */
    private function renderMember(array $data, array $meta): array
    {
        $member = isset($data['member']) && is_array($data['member']) ? $data['member'] : [];
        $summary = isset($data['summary']) && is_array($data['summary']) ? $data['summary'] : [];

        $facts = [
            ['label' => Lang::t($this->locale, 'label_member'), 'value' => (string)($member['member_name'] ?? $member['member_id'] ?? '-')],
            ['label' => 'ID', 'value' => (string)($member['member_id'] ?? '-')],
            ['label' => Lang::t($this->locale, 'label_type'), 'value' => (string)($member['member_type_name'] ?? '-')],
            ['label' => Lang::t($this->locale, 'label_expiry'), 'value' => $this->date($member['expire_date'] ?? '')],
            ['label' => Lang::t($this->locale, 'label_active_loans'), 'value' => (string)(int)($summary['active_loans'] ?? 0)],
            ['label' => Lang::t($this->locale, 'label_overdue_loans'), 'value' => (string)(int)($summary['overdue_loans'] ?? 0)],
            ['label' => Lang::t($this->locale, 'label_fines'), 'value' => $this->money((int)($summary['outstanding_fines'] ?? 0))],
        ];

        $blocks = [$this->block('facts', ['items' => $facts])];

        if (!empty($member['is_expired'])) {
            $blocks[] = $this->block('notice', ['tone' => 'warn', 'text' => 'membership expired']);
        } elseif (!empty($member['is_pending'])) {
            $blocks[] = $this->block('notice', ['tone' => 'warn', 'text' => 'membership pending']);
        }

        if (!empty($data['loans'])) {
            $loans = $this->renderLoans(['items' => $data['loans'], 'total' => count($data['loans'])], true, $meta);
            if (!empty($loans['reply']['blocks'])) {
                foreach ($loans['reply']['blocks'] as $block) {
                    $blocks[] = $block;
                }
            }
        }

        return $this->respond(
            Lang::t($this->locale, 'member_header', ['id' => (string)($member['member_id'] ?? '')]),
            $blocks,
            $meta
        );
    }

    /**
     * @param array $data
     * @param array $meta
     * @return array
     */
    private function renderReport(array $data, array $meta): array
    {
        $summary = isset($data['summary']) && is_array($data['summary']) ? $data['summary'] : [];
        $collection = (array)($summary['collection'] ?? []);
        $members = (array)($summary['members'] ?? []);
        $circulation = (array)($summary['circulation'] ?? []);
        $fines = (array)($summary['fines'] ?? []);

        $facts = [
            ['label' => Lang::t($this->locale, 'label_items_total'), 'value' => $this->number($collection['items'] ?? 0)],
            ['label' => Lang::t($this->locale, 'label_available_total'), 'value' => $this->number($collection['items_available'] ?? 0)],
            ['label' => Lang::t($this->locale, 'label_members_total'), 'value' => $this->number($members['total'] ?? 0)],
            ['label' => Lang::t($this->locale, 'label_loans_today'), 'value' => $this->number($circulation['loans_today'] ?? 0)],
            ['label' => Lang::t($this->locale, 'label_returns_today'), 'value' => $this->number($circulation['returns_today'] ?? 0)],
            ['label' => Lang::t($this->locale, 'label_overdue_total'), 'value' => $this->number($circulation['overdue'] ?? 0)],
            ['label' => Lang::t($this->locale, 'label_fines'), 'value' => $this->money((int)($fines['outstanding'] ?? 0))],
        ];

        return $this->respond(
            Lang::t($this->locale, 'report_header'),
            [$this->block('facts', ['items' => $facts])],
            $meta
        );
    }

    /**
     * @param array $data
     * @param array $meta
     * @return array
     */
    private function renderDoctor(array $data, array $meta): array
    {
        $instance = (array)($data['instance'] ?? []);
        $gates = (array)($data['gates'] ?? []);

        $facts = [
            ['label' => 'SLiMS', 'value' => (string)($instance['slims_version'] ?? '-')],
            ['label' => 'PHP', 'value' => (string)($instance['php_version'] ?? '-')],
            ['label' => 'DB', 'value' => (string)($instance['database'] ?? '-')],
            ['label' => Lang::t($this->locale, 'label_gate_write'), 'value' => !empty($gates['write_enabled']) ? 'on' : 'off'],
            ['label' => Lang::t($this->locale, 'label_gate_bulk'), 'value' => !empty($gates['bulk_enabled']) ? 'on' : 'off'],
        ];

        $checks = [];
        foreach ((array)($data['checks'] ?? []) as $check) {
            $checks[] = [
                'title' => (string)($check['name'] ?? '-'),
                'meta' => (string)($check['detail'] ?? ''),
                'badge' => !empty($check['ok']) ? 'ok' : 'check',
                'tone' => !empty($check['ok']) ? 'ok' : 'warn',
            ];
        }

        $blocks = [$this->block('facts', ['items' => $facts])];
        if (!empty($checks)) {
            $blocks[] = ['type' => 'list', 'items' => $checks];
        }

        return $this->respond(Lang::t($this->locale, 'doctor_header'), $blocks, $meta);
    }

    // -----------------------------------------------------------------
    // Writes (always two steps)
    // -----------------------------------------------------------------

    /**
     * @param string $name
     * @param array  $params
     * @return array
     */
    private function writeFlow(string $name, array $params): array
    {
        $missing = $this->missingWriteParameter($name, $params);

        if (!is_null($missing)) {
            return $this->plain(Lang::t($this->locale, $missing), 'info');
        }

        if ($name === Intent::MY_EXTEND) {
            $ownership = $this->assertOwnLoan($params);

            if (!is_null($ownership)) {
                return $this->plain(Lang::t($this->locale, $ownership), 'warn');
            }
        }

        $plan = $this->runIntent($name, $params, false);

        if (!$plan['ok']) {
            return $this->failure($plan['error']);
        }

        $blocks = $this->planBlocks($name, $plan['data']);
        $gateOpen = Policy::writeAllowed($this->role, $name, $this->config);

        if (!$gateOpen) {
            $key = $name === Intent::MY_EXTEND ? 'ui_member_write_off' : 'write_disabled';
            $blocks[] = $this->block('notice', ['tone' => 'info', 'text' => Lang::t($this->locale, $key)]);

            return $this->respond(Lang::t($this->locale, 'preview_header'), $blocks, [
                'intent' => $name,
                'confidence' => 1.0,
            ]);
        }

        $token = Confirm::issue([
            'intent' => $name,
            'params' => $params,
            'role' => $this->role,
            'actor' => $this->actorKey(),
        ], $this->ttl);

        $blocks[] = $this->block('notice', [
            'tone' => 'warn',
            'text' => Lang::t($this->locale, 'preview_hint', ['ttl' => $this->ttl]),
        ]);

        return $this->respond(Lang::t($this->locale, 'preview_header'), $blocks, [
            'intent' => $name,
            'confidence' => 1.0,
        ], [
            'token' => $token,
            'intent' => $name,
            'expires_in' => $this->ttl,
        ]);
    }

    /**
     * @param string $name
     * @param array  $params
     * @return string|null
     */
    private function missingWriteParameter(string $name, array $params)
    {
        switch ($name) {
            case Intent::LOAN_CHECKOUT:
                if (empty($params['member']) && empty($params['items'])) {
                    return 'ask_items_and_member';
                }

                return empty($params['member']) ? 'ask_member' : (empty($params['items']) ? 'ask_item' : null);

            case Intent::LOAN_CHECKIN:
                return empty($params['loan_id']) && empty($params['item_code']) ? 'ask_loan' : null;

            case Intent::LOAN_EXTEND:
            case Intent::MY_EXTEND:
                return empty($params['loan_id']) && empty($params['item_code']) ? 'ask_loan' : null;
        }

        return null;
    }

    /**
     * A member may only renew a loan of their own account.
     *
     * @param array $params
     * @return string|null language key when refused
     */
    private function assertOwnLoan(array $params)
    {
        $memberId = (string)$this->actor['member_id'];

        if ($memberId === '') {
            return 'need_login';
        }

        $result = $this->runIntent(Intent::MY_LOANS, ['status' => 'all', 'member' => $memberId, 'limit' => 20], false);

        if (!$result['ok']) {
            return 'error_generic';
        }

        $loanId = (int)($params['loan_id'] ?? 0);
        $itemCode = strtoupper(trim((string)($params['item_code'] ?? '')));

        foreach ((array)($result['data']['items'] ?? []) as $loan) {
            $matches = $loanId > 0
                ? (int)$loan['loan_id'] === $loanId
                : strtoupper((string)$loan['item_code']) === $itemCode;

            if ($matches) {
                return null;
            }
        }

        return 'member_not_self';
    }

    /**
     * @param string $token
     * @return array
     */
    private function handleConfirm(string $token): array
    {
        $outcome = Confirm::consume($token);

        if (!$outcome['valid']) {
            $key = $outcome['reason'] === 'expired' ? 'confirm_expired' : 'confirm_invalid';

            return $this->plain(Lang::t($this->locale, $key), 'warn');
        }

        $action = (array)$outcome['payload']['action'];

        if (($action['role'] ?? '') !== $this->role || (string)($action['actor'] ?? '') !== $this->actorKey()) {
            return $this->plain(Lang::t($this->locale, 'confirm_invalid'), 'warn');
        }

        return $this->commitWrite([
            'name' => (string)($action['intent'] ?? ''),
            'params' => (array)($action['params'] ?? []),
        ]);
    }

    /**
     * Execute a write that has already been previewed.
     *
     * The browser reaches this through a confirmed token; the console
     * command reaches it with `--commit`, where the flag itself is the
     * confirmation. Gates are re-checked either way.
     *
     * @param array $intent name + params
     * @return array
     */
    public function commitWrite(array $intent): array
    {
        $name = (string)($intent['name'] ?? '');
        $params = (array)($intent['params'] ?? []);

        if (!Policy::allows($this->role, $name) || !Policy::writeAllowed($this->role, $name, $this->config)) {
            return $this->plain(Lang::t($this->locale, 'write_disabled'), 'warn');
        }

        if (!RateLimit::hit(RateLimit::WRITES, (int)($this->config['max_writes_per_hour'] ?? 5), 3600)) {
            return $this->plain(Lang::t($this->locale, 'rate_limited'), 'warn');
        }

        if ($name === Intent::MY_EXTEND) {
            $ownership = $this->assertOwnLoan($params);

            if (!is_null($ownership)) {
                return $this->plain(Lang::t($this->locale, $ownership), 'warn');
            }
        }

        $result = $this->runIntent($name, $params, true);

        if (!$result['ok']) {
            return $this->failure($result['error']);
        }

        $blocks = $this->planBlocks($name, $result['data']);
        $blocks[] = $this->block('notice', [
            'tone' => 'success',
            'text' => Lang::t($this->locale, 'committed'),
        ]);

        return $this->respond(Lang::t($this->locale, 'committed'), $blocks, [
            'intent' => $name,
            'confidence' => 1.0,
        ], ['committed' => true]);
    }

    /**
     * @param string $name
     * @param array  $data
     * @return array
     */
    private function planBlocks(string $name, array $data): array
    {
        $blocks = [];

        if ($name === Intent::LOAN_CHECKOUT) {
            $facts = [
                ['label' => Lang::t($this->locale, 'label_member'), 'value' => (string)($data['member_id'] ?? '-') . ' · ' . (string)($data['member_name'] ?? '')],
            ];

            $items = [];
            foreach ((array)($data['items'] ?? []) as $entry) {
                $items[] = [
                    'title' => (string)($entry['title'] ?? $entry['item_code'] ?? '-'),
                    'meta' => (string)($entry['item_code'] ?? '') . (!empty($entry['due_date']) ? ' · ' . Lang::t($this->locale, 'label_due') . ' ' . $this->date($entry['due_date']) : ''),
                    'badge' => (string)($entry['status'] ?? ''),
                    'tone' => !empty($entry['ready']) ? 'ok' : 'warn',
                ];
            }

            $blocks[] = $this->block('facts', ['items' => $facts]);
            if (!empty($items)) {
                $blocks[] = ['type' => 'list', 'items' => $items];
            }
            if (empty($data['ready'])) {
                $blocks[] = $this->block('notice', ['tone' => 'warn', 'text' => 'one or more items cannot be loaned; nothing will be written']);
            }
        } elseif ($name === Intent::LOAN_CHECKIN) {
            $plan = (array)($data['plan'] ?? []);
            $blocks[] = $this->block('facts', ['items' => [
                ['label' => Lang::t($this->locale, 'label_loan_id'), 'value' => '#' . (int)($plan['loan_id'] ?? 0)],
                ['label' => Lang::t($this->locale, 'label_title'), 'value' => (string)($plan['title'] ?? '-')],
                ['label' => 'item', 'value' => (string)($plan['item_code'] ?? '-')],
                ['label' => Lang::t($this->locale, 'label_due'), 'value' => $this->date($plan['due_date'] ?? '')],
                ['label' => Lang::t($this->locale, 'label_overdue_loans'), 'value' => (string)(int)($plan['overdue_days'] ?? 0)],
                ['label' => Lang::t($this->locale, 'label_fines'), 'value' => $this->money((int)($plan['fine_amount'] ?? 0))],
            ]]);
        } elseif ($name === Intent::LOAN_EXTEND || $name === Intent::MY_EXTEND) {
            $plan = (array)($data['plan'] ?? []);
            $blocks[] = $this->block('facts', ['items' => [
                ['label' => Lang::t($this->locale, 'label_loan_id'), 'value' => '#' . (int)($plan['loan_id'] ?? 0)],
                ['label' => Lang::t($this->locale, 'label_title'), 'value' => (string)($plan['title'] ?? '-')],
                ['label' => 'item', 'value' => (string)($plan['item_code'] ?? '-')],
                ['label' => Lang::t($this->locale, 'label_due'), 'value' => $this->date($plan['current_due_date'] ?? '') . ' → ' . $this->date($plan['projected_due_date'] ?? '')],
                ['label' => Lang::t($this->locale, 'label_renewed'), 'value' => (int)($plan['renewed'] ?? 0) . '/' . (int)($plan['reborrow_limit'] ?? 0)],
            ]]);

            if (empty($plan['renewable'])) {
                $messages = [];
                foreach ((array)($plan['blocked'] ?? []) as $blocked) {
                    $messages[] = (string)($blocked['message'] ?? '');
                }
                $blocks[] = $this->block('notice', [
                    'tone' => 'warn',
                    'text' => implode(' ', array_filter($messages)),
                ]);
            }
        }

        return $blocks;
    }

    // -----------------------------------------------------------------
    // Service access
    // -----------------------------------------------------------------

    /**
     * @return object
     */
    private function service()
    {
        if (is_null($this->service)) {
            $class = 'Commands\\Support\\CliAnything';

            if (!class_exists($class)) {
                throw new \RuntimeException('CLI-Anything service is not available (plugins/Commands/Support missing).');
            }

            $this->service = $class::instance();
        }

        if (method_exists($this->service, 'actingAs')) {
            if ($this->role === Policy::MEMBER) {
                $this->service->actingAs('member', (string)$this->actor['member_id'], (string)$this->actor['name']);
            } else {
                $this->service->actingAs('staff', (string)($this->actor['id'] ?: '0'), (string)$this->actor['name']);
            }
        }

        return $this->service;
    }

    /**
     * @param string $name
     * @param array  $params
     * @param bool   $commit
     * @return array{ok:bool,data?:array,error?:array}
     */
    private function runIntent(string $name, array $params, bool $commit): array
    {
        try {
            $service = $this->service();
            $limit = (int)($params['limit'] ?? 5);

            switch ($name) {
                case Intent::BIBLIO_LIST:
                    $data = $service->biblioList((string)($params['q'] ?? ''), $limit, 0);
                    break;

                case Intent::BIBLIO_GET:
                    $data = $service->biblioGet((int)($params['id'] ?? 0));
                    break;

                case Intent::ITEM_GET:
                    $data = $service->itemGet((string)($params['code'] ?? ''));
                    break;

                case Intent::LOAN_LIST:
                    $data = $service->loanList((string)($params['status'] ?? 'active'), (string)($params['member'] ?? ''), $limit);
                    break;

                case Intent::MEMBER_GET:
                    $data = $service->memberGet((string)($params['member'] ?? ''), true);
                    break;

                case Intent::MY_LOANS:
                    $data = $service->loanList((string)($params['status'] ?? 'active'), (string)$this->actor['member_id'], $limit);
                    break;

                case Intent::MY_PROFILE:
                    $data = $service->memberGet((string)$this->actor['member_id'], false);
                    break;

                case Intent::REPORT_SUMMARY:
                    $data = $service->reportSummary();
                    break;

                case Intent::DOCTOR:
                    $data = $service->doctor();
                    break;

                case Intent::LOAN_CHECKOUT:
                    $data = $service->loanCheckout((string)($params['member'] ?? ''), (array)($params['items'] ?? []), $commit);
                    break;

                case Intent::LOAN_CHECKIN:
                    $data = $service->loanCheckin(
                        !empty($params['loan_id']) ? (int)$params['loan_id'] : null,
                        !empty($params['item_code']) ? (string)$params['item_code'] : null,
                        $commit
                    );
                    break;

                case Intent::LOAN_EXTEND:
                case Intent::MY_EXTEND:
                    $data = $service->loanExtend(
                        !empty($params['loan_id']) ? (int)$params['loan_id'] : null,
                        $commit,
                        !empty($params['item_code']) ? (string)$params['item_code'] : null
                    );
                    break;

                default:
                    throw new \RuntimeException('Unsupported intent: ' . $name);
            }

            return ['ok' => true, 'data' => $data];
        } catch (\Throwable $error) {
            $code = 'UNEXPECTED_ERROR';

            if (class_exists('Commands\\Support\\CliAnythingException') && $error instanceof \Commands\Support\CliAnythingException) {
                $code = $error->errorCode();
            }

            return [
                'ok' => false,
                'error' => [
                    'code' => $code,
                    'message' => $error->getMessage(),
                ],
            ];
        }
    }

    // -----------------------------------------------------------------
    // Reply construction
    // -----------------------------------------------------------------

    /**
     * @param string $text
     * @param array  $blocks
     * @param array  $meta
     * @param array  $extra
     * @return array
     */
    private function respond(string $text, array $blocks = [], array $meta = [], array $extra = []): array
    {
        $response = [
            'ok' => true,
            'schema' => $this->schema(),
            'role' => $this->role,
            'locale' => $this->locale,
            'reply' => [
                'text' => $text,
                'blocks' => array_values($blocks),
            ],
            'suggestions' => Intent::chips($this->role, $this->locale),
            'pending' => null,
            'meta' => array_merge([
                'writes_enabled' => Policy::writeAllowed($this->role, $this->role === Policy::MEMBER ? Intent::MY_EXTEND : Intent::LOAN_CHECKOUT, $this->config),
                'confirm_ttl' => $this->ttl,
            ], $meta),
        ];

        if (!empty($extra['token'])) {
            $response['pending'] = [
                'token' => (string)$extra['token'],
                'intent' => (string)($extra['intent'] ?? ''),
                'expires_in' => (int)($extra['expires_in'] ?? $this->ttl),
                'label' => Lang::t($this->locale, 'ui_confirm'),
            ];
        }

        foreach ($extra as $key => $value) {
            if ($key !== 'token') {
                $response['meta'][$key] = $value;
            }
        }

        return $response;
    }

    /**
     * Short answer with an optional notice tone.
     *
     * @param string $text
     * @param string $tone
     * @param array  $blocks
     * @return array
     */
    private function plain(string $text, string $tone = '', array $blocks = []): array
    {
        $response = $this->respond($text, $blocks);

        // The tone travels as metadata instead of a duplicated notice block:
        // the browser colours the bubble, the text is written once.
        if ($tone !== '') {
            $response['meta']['tone'] = $tone;
        }

        return $response;
    }

    /**
     * @param array $error
     * @return array
     */
    private function failure(array $error): array
    {
        $code = (string)($error['code'] ?? 'UNEXPECTED_ERROR');
        $message = (string)($error['message'] ?? '');

        $response = $this->plain(Lang::t($this->locale, 'error_code', [
            'code' => $code,
            'message' => $message,
        ]), 'error');

        $response['ok'] = false;
        $response['error'] = ['code' => $code, 'message' => $message];

        return $response;
    }

    /**
     * @return array
     */
    private function loginLink(): array
    {
        $href = defined('SWB') ? SWB . 'index.php?p=member' : 'index.php?p=member';

        return $this->block('link', [
            'label' => Lang::t($this->locale, 'ui_login'),
            'href' => $href,
        ]);
    }

    /**
     * @param int $biblioId
     * @return string
     */
    private function opacBiblioLink(int $biblioId): string
    {
        if ($biblioId <= 0 || !defined('SWB')) {
            return '';
        }

        return SWB . 'index.php?p=show_detail&id=' . $biblioId;
    }

    /**
     * @param string $type
     * @param array  $data
     * @return array
     */
    private function block(string $type, array $data = []): array
    {
        return array_merge(['type' => $type], $data);
    }

    /**
     * @return array
     */
    private function greetingPlaceholders(): array
    {
        $name = trim((string)($this->actor['name'] ?? ''));

        return ['name' => $name === '' ? (string)($this->actor['member_id'] ?: 'there') : $name];
    }

    /**
     * @return string
     */
    private function actorKey(): string
    {
        $id = $this->role === Policy::MEMBER ? (string)$this->actor['member_id'] : (string)$this->actor['id'];

        return $this->role . ':' . $id;
    }

    /**
     * @return string
     */
    private function schema(): string
    {
        if (class_exists('Commands\\Support\\CliAnything')) {
            return (string)\Commands\Support\CliAnything::SCHEMA_VERSION;
        }

        return '1.0';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function date($value): string
    {
        $value = trim((string)$value);

        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '-';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('d/m/Y', $timestamp);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function number($value): string
    {
        return number_format((float)$value, 0, $this->locale === 'id' ? ',' : '.', $this->locale === 'id' ? '.' : ',');
    }

    /**
     * @param int $value
     * @return string
     */
    private function money(int $value): string
    {
        return $this->number($value);
    }
}
