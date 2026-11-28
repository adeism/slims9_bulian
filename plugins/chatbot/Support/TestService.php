<?php
/**
 * Deterministic stand-in for Commands\Support\CliAnything
 *
 * The chat must be testable without a database, so this class implements
 * the same method set with fixed data. It is used by `chatbot:selftest` and
 * by `plugins/chatbot/tests/run.php`; it is never wired into a page.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class TestService
{
    /** @var array */
    public $calls = [];

    /** @var array */
    public $lastActor = [];

    /**
     * Method names that should throw instead of answering, so the failure
     * paths (database down, damaged table) can be exercised offline.
     *
     * @var string[]
     */
    public $throwOn = [];

    /**
     * Queries that return nothing, so the "no results" branch can be tested
     * with a real sentence instead of a magic token.
     *
     * @var string[]
     */
    public $emptyQueries = [];

    /**
     * @return TestService
     */
    public function actingAs(string $type, string $id, string $name = ''): TestService
    {
        $this->lastActor = ['type' => $type, 'id' => $id, 'name' => $name];
        $this->calls[] = ['actingAs', [$type, $id]];

        return $this;
    }

    /**
     * @return string
     */
    public function lastCall(): string
    {
        $last = end($this->calls);

        return $last === false ? '' : (string)$last[0];
    }

    /**
     * @param string $name
     * @return array|null
     */
    public function callArgs(string $name)
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call[0] === $name) {
                return $call[1];
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public function biblioList(string $q = '', int $limit = 20, int $offset = 0, string $sort = 'relevance', bool $includeHidden = false, array $options = []): array
    {
        if (in_array('biblio.list', $this->throwOn, true)) {
            throw new \RuntimeException('Table biblio is damaged near "SELECT * FROM member"');
        }

        $this->calls[] = ['biblio.list', [$q, $limit, $offset, $sort, $includeHidden, $options]];

        if (trim($q) === 'zzz' || in_array(trim($q), $this->emptyQueries, true)) {
            return [
                'items' => [], 'count' => 0, 'total' => 0, 'limit' => $limit, 'offset' => 0,
                'query' => $q, 'tokens' => ['zzz'], 'sort' => $sort, 'stage' => 'empty', 'suggestions' => [],
            ];
        }

        // A typo that the exact stage misses; the real service shortens the
        // token and finds the title anyway.
        if (in_array(trim($q), ['sejarh', 'sejara'], true)) {
            return [
                'items' => [$this->row(12, 'Sejarah Indonesia Modern', 'Budi Santoso', '2019', '959.8 BUD s', 3, 1, 41)],
                'count' => 1, 'total' => 1, 'limit' => $limit, 'offset' => $offset,
                'query' => $q, 'tokens' => [trim($q)], 'sort' => $sort, 'stage' => 'prefix', 'suggestions' => [],
            ];
        }

        // Nothing close enough: the service answers with did-you-mean titles.
        if (in_array(trim($q), ['laskr', 'laskar pelangii'], true)) {
            return [
                'items' => [
                    $this->row(21, 'Laskar Pelangi', 'Andrea Hirata', '2005', '899.221 AND l', 2, 0, 87),
                    $this->row(22, 'Laskar Pelangi 2', 'Andrea Hirata', '2008', '899.221 AND l', 1, 0, 12),
                ],
                'count' => 2, 'total' => 2, 'limit' => $limit, 'offset' => $offset,
                'query' => $q, 'tokens' => [trim($q)], 'sort' => $sort, 'stage' => 'suggestion',
                'suggestions' => [
                    ['biblio_id' => 21, 'title' => 'Laskar Pelangi', 'similarity' => 0.86],
                    ['biblio_id' => 22, 'title' => 'Laskar Pelangi 2', 'similarity' => 0.79],
                ],
            ];
        }

        return [
            'items' => [
                [
                    'biblio_id' => 12,
                    'title' => 'Sejarah Indonesia Modern',
                    'authors' => 'Budi Santoso',
                    'isbn_issn' => '9786020000001',
                    'publish_year' => '2019',
                    'call_number' => '959.8 BUD s',
                    'item_count' => 3,
                    'loan_count' => 1,
                    'available_count' => 2,
                    'popularity' => 41,
                    'rank_score' => 120,
                ],
                [
                    'biblio_id' => 13,
                    'title' => 'Sejarah Nusantara',
                    'authors' => 'Siti Aminah',
                    'isbn_issn' => '9786020000002',
                    'publish_year' => '2020',
                    'call_number' => '959.8 SIT s',
                    'item_count' => 1,
                    'loan_count' => 1,
                    'available_count' => 0,
                    'popularity' => 7,
                    'rank_score' => 70,
                ],
            ],
            'count' => 2,
            'total' => 2,
            'limit' => $limit,
            'offset' => $offset,
            'query' => $q,
            'tokens' => $q === '' ? [] : explode(' ', trim($q)),
            'sort' => $sort,
            'stage' => 'exact',
            'suggestions' => [],
        ];
    }


    /**
     * One bibliography row for the fixtures above.
     *
     * @return array
     */
    private function row(int $id, string $title, string $authors, string $year, string $callNumber, int $items, int $loans, int $popularity): array
    {
        return [
            'biblio_id' => $id,
            'title' => $title,
            'authors' => $authors,
            'isbn_issn' => '',
            'publish_year' => $year,
            'call_number' => $callNumber,
            'item_count' => $items,
            'loan_count' => $loans,
            'available_count' => $items - $loans,
            'popularity' => $popularity,
            'rank_score' => 120,
        ];
    }

    /**
     * @return array
     */
    public function biblioGet(int $biblioId): array
    {
        $this->calls[] = ['biblio.get', [$biblioId]];

        if ($biblioId !== 12) {
            throw new \RuntimeException('Bibliography #' . $biblioId . ' not found.');
        }

        return [
            'biblio' => [
                'biblio_id' => 12,
                'title' => 'Sejarah Indonesia Modern',
                'isbn_issn' => '9786020000001',
                'call_number' => '959.8 BUD s',
                'publish_year' => '2019',
                'publisher_name' => 'Pustaka Nusantara',
            ],
            'authors' => [['author_id' => 1, 'author_name' => 'Budi Santoso', 'level' => 1]],
            'topics' => [],
            'items' => [
                [
                    'item_id' => 1,
                    'item_code' => 'B00001',
                    'call_number' => '959.8 BUD s',
                    'location_name' => 'Lantai 1',
                    'item_status_name' => 'Available',
                    'on_loan' => 0,
                    'loanable' => true,
                ],
                [
                    'item_id' => 2,
                    'item_code' => 'B00002',
                    'call_number' => '959.8 BUD s',
                    'location_name' => 'Lantai 1',
                    'item_status_name' => 'Available',
                    'on_loan' => 1,
                    'loanable' => false,
                ],
            ],
        ];
    }

    /**
     * @return array
     */
    public function itemGet(string $itemCode): array
    {
        $this->calls[] = ['item.get', [$itemCode]];

        if (strtoupper($itemCode) !== 'B00001' && strtoupper($itemCode) !== 'B00002') {
            throw new \RuntimeException('Item ' . $itemCode . ' not found.');
        }

        $onLoan = strtoupper($itemCode) === 'B00002';

        return [
            'item' => [
                'item_id' => $onLoan ? 2 : 1,
                'item_code' => strtoupper($itemCode),
                'biblio_id' => 12,
                'title' => 'Sejarah Indonesia Modern',
                'call_number' => '959.8 BUD s',
                'location_name' => 'Lantai 1',
                'item_status_name' => 'Available',
                'no_loan' => 0,
                'on_loan' => $onLoan ? 1 : 0,
                'loanable' => !$onLoan,
            ],
            'active_loan' => $onLoan
                ? [
                    'loan_id' => 77,
                    'member_id' => '123',
                    'member_name' => 'Rina',
                    'loan_date' => '2026-11-01',
                    'due_date' => '2026-11-08',
                    'overdue_days' => 19,
                ]
                : null,
        ];
    }

    /**
     * @return array
     */
    public function loanList(string $status = 'active', string $memberId = '', int $limit = 20): array
    {
        $this->calls[] = ['loan.list', [$status, $memberId, $limit]];

        $row = [
            'loan_id' => 77,
            'item_code' => 'B00001',
            'member_id' => $memberId === '' ? '123' : $memberId,
            'member_name' => 'Rina',
            'title' => 'Sejarah Indonesia Modern',
            'biblio_id' => 12,
            'loan_date' => '2026-11-01',
            'due_date' => '2026-11-08',
            'return_date' => null,
            'renewed' => 0,
            'overdue_days' => 19,
        ];

        if ($status === 'returned' || $memberId === 'nobody') {
            return ['items' => [], 'count' => 0, 'total' => 0, 'status' => $status, 'member_id' => $memberId, 'limit' => $limit];
        }

        return [
            'items' => [$row],
            'count' => 1,
            'total' => 1,
            'status' => $status,
            'member_id' => $memberId === '' ? null : $memberId,
            'limit' => $limit,
        ];
    }

    /**
     * @return array
     */
    public function memberGet(string $memberId, bool $redact = true): array
    {
        $this->calls[] = ['member.get', [$memberId, $redact]];

        if ($memberId !== '123') {
            throw new \RuntimeException('Member ' . $memberId . ' not found.');
        }

        return [
            'member' => [
                'member_id' => '123',
                'member_name' => 'Rina Kartika',
                'member_type_name' => 'Standard',
                'member_email' => $redact ? '***redacted***' : 'rina@example.test',
                'expire_date' => '2027-01-31',
                'is_expired' => false,
                'is_pending' => false,
            ],
            'summary' => [
                'active_loans' => 1,
                'overdue_loans' => 1,
                'outstanding_fines' => 6000,
            ],
            'loans' => [],
            'pii_redacted' => $redact,
        ];
    }

    /**
     * @return array
     */
    public function reportSummary(): array
    {
        $this->calls[] = ['report.summary', []];

        return [
            'summary' => [
                'collection' => ['biblio' => 10, 'items' => 10, 'items_available' => 9],
                'members' => ['total' => 4, 'pending' => 0, 'expired' => 0],
                'circulation' => ['loans_today' => 3, 'returns_today' => 2, 'active' => 1, 'overdue' => 1],
                'fines' => ['outstanding' => 6000],
            ],
        ];
    }

    /**
     * @return array
     */
    public function doctor(): array
    {
        $this->calls[] = ['doctor', []];

        return [
            'instance' => [
                'slims_version' => '9.6.0',
                'php_version' => '8.4.1',
                'database' => 'slims',
            ],
            'gates' => ['write_enabled' => true, 'bulk_enabled' => false],
            'counts' => [],
            'checks' => [
                ['name' => 'database', 'ok' => true, 'detail' => 'ok'],
                ['name' => 'backup_directory', 'ok' => false, 'detail' => '/tmp'],
            ],
        ];
    }

    /**
     * @return array
     */
    public function loanCheckout(string $memberId, array $itemCodes, bool $commit = false): array
    {
        $this->calls[] = ['loan.checkout', [$memberId, $itemCodes, $commit]];

        $items = [];
        foreach ($itemCodes as $code) {
            $items[] = [
                'item_code' => strtoupper((string)$code),
                'status_code' => 1,
                'status' => 'ready',
                'ready' => true,
                'title' => 'Sejarah Indonesia Modern',
                'loan_date' => '2026-11-27',
                'due_date' => '2026-12-04',
            ];
        }

        return [
            'operation' => 'loan.checkout',
            'member_id' => $memberId,
            'member_name' => 'Rina Kartika',
            'items' => $items,
            'ready' => true,
            'dry_run' => !$commit,
            'committed' => $commit,
            'backup' => null,
            'receipt' => [],
        ];
    }

    /**
     * @return array
     */
    public function loanCheckin(?int $loanId, ?string $itemCode, bool $commit = false): array
    {
        $this->calls[] = ['loan.checkin', [$loanId, $itemCode, $commit]];

        return [
            'operation' => 'loan.checkin',
            'dry_run' => !$commit,
            'committed' => $commit,
            'plan' => [
                'loan_id' => $loanId === null ? 77 : $loanId,
                'item_code' => $itemCode === null ? 'B00001' : $itemCode,
                'title' => 'Sejarah Indonesia Modern',
                'member_id' => '123',
                'member_name' => 'Rina Kartika',
                'loan_date' => '2026-11-01',
                'due_date' => '2026-11-08',
                'return_date' => '2026-11-27',
                'overdue_days' => 19,
                'fine_amount' => 6000,
            ],
            'reserved_for_other_member' => false,
            'receipt' => [],
        ];
    }

    /**
     * @return array
     */
    public function loanExtend(?int $loanId, bool $commit = false, ?string $itemCode = null): array
    {
        $this->calls[] = ['loan.extend', [$loanId, $commit, $itemCode]];

        $blocked = $loanId === 99
            ? [['code' => 'RENEW_LIMIT_REACHED', 'message' => 'Loan #99 already renewed 2 time(s), reborrow limit is 2.']]
            : [];

        return [
            'operation' => 'loan.extend',
            'dry_run' => !$commit,
            'committed' => $commit,
            'plan' => [
                'loan_id' => $loanId === null ? 77 : $loanId,
                'item_code' => $itemCode === null ? 'B00001' : $itemCode,
                'member_id' => '123',
                'member_name' => 'Rina Kartika',
                'title' => 'Sejarah Indonesia Modern',
                'current_due_date' => '2026-11-08',
                'renewed' => 0,
                'reborrow_limit' => 1,
                'reserved_for_other_member' => false,
                'projected_due_date' => '2026-12-04',
                'renewable' => empty($blocked),
                'blocked' => $blocked,
            ],
        ];
    }
}
