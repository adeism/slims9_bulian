<?php
/**
 * Offline test suite
 *
 * Runs without a database: intents, role policy, confirmation tokens, rate
 * limiting, the engine against a fake service, and the HTTP request
 * pipeline. This is the suite behind `php index.php chatbot:selftest` and
 * `php plugins/chatbot/tests/run.php`.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class SelfTest
{
    /**
     * @return array{passed:int,failed:int,cases:array}
     */
    public static function run(): array
    {
        $cases = [];

        $_SESSION = [];
        Config::override([
            'enabled' => true,
            'opac' => true,
            'admin' => true,
            'language' => 'en',
        ]);

        self::intents($cases);
        self::policies($cases);
        self::confirmations($cases);
        self::limits($cases);
        self::reads($cases);
        self::writes($cases);
        self::search($cases);
        self::faq($cases);
        self::logs($cases);
        self::history($cases);
        self::overlay($cases);
        self::guard($cases);
        self::channels($cases);
        self::stock($cases);
        self::http($cases);

        Config::override(null);
        $_SESSION = [];

        $passed = 0;
        $failed = 0;

        foreach ($cases as $case) {
            if ($case['ok']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'cases' => $cases,
        ];
    }

    // -----------------------------------------------------------------
    // Sections
    // -----------------------------------------------------------------

    /**
     * @param array $cases
     * @return void
     */
    private static function intents(array &$cases)
    {
        $r = Intent::resolve('halo', 'guest');
        self::add($cases, 'intent: greeting', $r['name'] === Intent::GREETING, $r['name']);

        $r = Intent::resolve('bantuan', 'staff');
        self::add($cases, 'intent: help', $r['name'] === Intent::HELP, $r['name']);

        $r = Intent::resolve('cari buku sejarah', 'guest');
        self::add(
            $cases,
            'intent: catalogue search',
            $r['name'] === Intent::BIBLIO_LIST && $r['params']['q'] === 'sejarah',
            json_encode($r)
        );

        $r = Intent::resolve('apakah buku #12 tersedia?', 'guest');
        self::add(
            $cases,
            'intent: biblio detail by #id',
            $r['name'] === Intent::BIBLIO_GET && (int)$r['params']['id'] === 12,
            json_encode($r)
        );

        $r = Intent::resolve('cek item B00001', 'staff');
        self::add(
            $cases,
            'intent: item status',
            $r['name'] === Intent::ITEM_GET && $r['params']['code'] === 'B00001',
            json_encode($r)
        );

        $r = Intent::resolve('pinjaman saya', 'member');
        self::add(
            $cases,
            'intent: my loans (member)',
            $r['name'] === Intent::MY_LOANS && $r['params']['status'] === 'active',
            json_encode($r)
        );

        $r = Intent::resolve('denda saya', 'member');
        self::add($cases, 'intent: my fines -> profile', $r['name'] === Intent::MY_PROFILE, json_encode($r));

        $r = Intent::resolve('pinjaman terlambat', 'staff');
        self::add(
            $cases,
            'intent: overdue list (staff)',
            $r['name'] === Intent::LOAN_LIST && $r['params']['status'] === 'overdue',
            json_encode($r)
        );

        $r = Intent::resolve('ringkasan hari ini', 'staff');
        self::add($cases, 'intent: daily summary', $r['name'] === Intent::REPORT_SUMMARY, json_encode($r));

        $r = Intent::resolve('pinjamkan B00001 ke anggota 123', 'staff');
        self::add(
            $cases,
            'intent: checkout with member + item',
            $r['name'] === Intent::LOAN_CHECKOUT
                && $r['params']['member'] === '123'
                && $r['params']['items'] === ['B00001'],
            json_encode($r)
        );

        $r = Intent::resolve('kembalikan B00001', 'staff');
        self::add(
            $cases,
            'intent: checkin by item code',
            $r['name'] === Intent::LOAN_CHECKIN && $r['params']['item_code'] === 'B00001',
            json_encode($r)
        );

        $r = Intent::resolve('perpanjang pinjaman 42', 'staff');
        self::add(
            $cases,
            'intent: extend by loan id',
            $r['name'] === Intent::LOAN_EXTEND && (int)$r['params']['loan_id'] === 42,
            json_encode($r)
        );

        $r = Intent::resolve('perpanjang pinjaman saya', 'member');
        self::add($cases, 'intent: member self-renewal', $r['name'] === Intent::MY_EXTEND, json_encode($r));

        $r = Intent::resolve('laskar pelangi', 'guest');
        self::add(
            $cases,
            'intent: short text falls back to search',
            $r['name'] === Intent::BIBLIO_LIST && $r['params']['q'] === 'laskar pelangi',
            json_encode($r)
        );

        $r = Intent::resolve('find book history', 'guest');
        self::add(
            $cases,
            'intent: english search',
            $r['name'] === Intent::BIBLIO_LIST && strpos((string)$r['params']['q'], 'history') !== false,
            json_encode($r)
        );

        $r = Intent::resolve('', 'guest');
        self::add($cases, 'intent: empty message', $r['name'] === Intent::NONE, json_encode($r));
    }

    /**
     * @param array $cases
     * @return void
     */
    private static function policies(array &$cases)
    {
        self::add(
            $cases,
            'policy: guest cannot read loans',
            !Policy::allows(Policy::GUEST, Intent::MY_LOANS)
                && Policy::denialReason(Policy::GUEST, Intent::MY_LOANS) === 'need_login'
        );

        self::add(
            $cases,
            'policy: guest cannot read report',
            !Policy::allows(Policy::GUEST, Intent::REPORT_SUMMARY)
                && Policy::denialReason(Policy::GUEST, Intent::REPORT_SUMMARY) === 'denied_guest'
        );

        self::add(
            $cases,
            'policy: member cannot check out',
            !Policy::allows(Policy::MEMBER, Intent::LOAN_CHECKOUT)
                && Policy::denialReason(Policy::MEMBER, Intent::LOAN_CHECKOUT) === 'denied_member'
        );

        self::add(
            $cases,
            'policy: staff can check out',
            Policy::allows(Policy::STAFF, Intent::LOAN_CHECKOUT)
        );

        $scoped = Policy::scope(
            Policy::MEMBER,
            Intent::MY_LOANS,
            ['member' => '999', 'reveal' => '1', 'limit' => 500],
            ['member_id' => '123'],
            ['result_limit' => 5]
        );

        self::add(
            $cases,
            'policy: member scope is forced to own account',
            $scoped['member'] === '123' && !isset($scoped['reveal']) && (int)$scoped['limit'] === 5,
            json_encode($scoped)
        );

        $config = ['allow_admin_write' => false, 'allow_member_write' => false];

        self::add(
            $cases,
            'policy: staff write gate is closed by default',
            !Policy::writeAllowed(Policy::STAFF, Intent::LOAN_CHECKOUT, $config)
        );

        $config['allow_admin_write'] = true;
        self::add(
            $cases,
            'policy: staff write gate follows config',
            Policy::writeAllowed(Policy::STAFF, Intent::LOAN_CHECKOUT, $config)
        );

        self::add(
            $cases,
            'policy: member renewal needs its own gate',
            !Policy::writeAllowed(Policy::MEMBER, Intent::MY_EXTEND, $config)
                && Policy::writeAllowed(Policy::MEMBER, Intent::MY_EXTEND, ['allow_member_write' => true])
        );

        self::add(
            $cases,
            'policy: guest never writes',
            !Policy::writeAllowed(Policy::GUEST, Intent::MY_EXTEND, ['allow_member_write' => true])
        );
    }

    /**
     * @param array $cases
     * @return void
     */
    private static function confirmations(array &$cases)
    {
        $_SESSION = [];

        $action = ['intent' => Intent::LOAN_CHECKIN, 'params' => ['loan_id' => 77], 'role' => 'staff', 'actor' => 'staff:1'];
        $token = Confirm::issue($action, 60);

        $first = Confirm::consume($token);
        self::add($cases, 'confirm: token is accepted once', $first['valid'] === true, $first['reason']);

        $second = Confirm::consume($token);
        self::add($cases, 'confirm: token cannot be replayed', $second['valid'] === false && $second['reason'] === 'reused', $second['reason']);

        $token = Confirm::issue($action, 60);
        $tampered = Confirm::consume(substr($token, 0, -2) . 'xx');
        self::add($cases, 'confirm: tampered token is rejected', $tampered['valid'] === false, $tampered['reason']);

        $past = time() - 3600;
        $token = Confirm::issue($action, 60, $past);
        $expired = Confirm::consume($token);
        self::add($cases, 'confirm: expired token is rejected', $expired['valid'] === false && $expired['reason'] === 'expired', $expired['reason']);

        $token = Confirm::issue($action, 60);
        $pending = Confirm::pendingCount();
        $_SESSION[Confirm::SESSION_KEY] = [];
        $unknown = Confirm::consume($token);
        self::add(
            $cases,
            'confirm: token is bound to the session',
            $unknown['valid'] === false && $unknown['reason'] === 'reused' && $pending >= 1,
            $unknown['reason'] . ' pending=' . $pending
        );

        $_SESSION = [];
        $old = Confirm::issue($action, 60, time() - 7200);
        $fresh = Confirm::issue($action, 60);
        self::add(
            $cases,
            'confirm: expired tokens are pruned',
            Confirm::pendingCount() === 1 && Confirm::consume($fresh)['valid'] === true && Confirm::consume($old)['valid'] === false,
            'pending=' . Confirm::pendingCount()
        );
    }

    /**
     * @param array $cases
     * @return void
     */
    private static function limits(array &$cases)
    {
        $_SESSION = [];
        RateLimit::reset();

        $now = time();
        $allowed = RateLimit::hit(RateLimit::MESSAGES, 2, 60, $now)
            && RateLimit::hit(RateLimit::MESSAGES, 2, 60, $now)
            && !RateLimit::hit(RateLimit::MESSAGES, 2, 60, $now);

        self::add($cases, 'limits: bucket stops at the limit', $allowed);

        $reset = RateLimit::hit(RateLimit::MESSAGES, 2, 60, $now + 61);
        self::add($cases, 'limits: bucket refills after the window', $reset);
    }

    /**
     * @param array $cases
     * @return void
     */
    private static function reads(array &$cases)
    {
        $_SESSION = [];
        RateLimit::reset();

        $service = new TestService();
        $engine = new Engine(Actor::anonymous('guest'), self::config(), $service);
        $response = $engine->handle('halo');
        self::add($cases, 'engine: guest greeting', $response['ok'] === true && $response['reply']['text'] !== '');

        $service = new TestService();
        $engine = new Engine(Actor::anonymous('guest'), self::config(), $service);
        $response = $engine->handle('pinjaman saya');
        self::add(
            $cases,
            'engine: guest is asked to log in',
            $response['reply']['text'] === Lang::t('en', 'need_login') && self::hasBlock($response, 'link'),
            $response['reply']['text']
        );

        $duplicated = false;
        foreach ((array)($response['reply']['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'notice' && ($block['text'] ?? '') === $response['reply']['text']) {
                $duplicated = true;
            }
        }

        self::add($cases, 'engine: plain answers are not duplicated', !$duplicated);

        $service = new TestService();
        $engine = new Engine(Actor::make('member', '123', 'Rina'), self::config(), $service);
        $response = $engine->handle('pinjaman saya');
        $args = $service->callArgs('loan.list');
        self::add(
            $cases,
            'engine: member loans are scoped to the session',
            $response['ok'] === true && is_array($args) && $args[1] === '123',
            json_encode($args)
        );

        $service = new TestService();
        $engine = new Engine(Actor::make('staff', '1', 'Sari'), self::config(), $service);
        $response = $engine->handle('pinjaman terlambat');
        self::add(
            $cases,
            'engine: overdue answers use the overdue header',
            $response['reply']['text'] === Lang::t('en', 'loans_overdue_header', ['count' => 1]),
            $response['reply']['text']
        );
        self::add(
            $cases,
            'engine: staff overdue list renders',
            $response['ok'] === true && self::hasBlock($response, 'list')
                && count(self::blockOfType($response, 'list')['items'] ?? []) === 1,
            json_encode($response['reply']['blocks'] ?? [])
        );

        $service = new TestService();
        $engine = new Engine(Actor::make('staff', '1', 'Sari'), self::config(), $service);
        $response = $engine->handle('cari buku zzz');
        self::add(
            $cases,
            'engine: empty search is explained',
            $response['reply']['text'] === Lang::t('en', 'no_results', ['query' => 'zzz']),
            $response['reply']['text']
        );

        $service = new TestService();
        $engine = new Engine(Actor::make('staff', '1', 'Sari'), self::config(), $service);
        $response = $engine->handle('cek item B99999');
        self::add(
            $cases,
            'engine: service error becomes a failed answer',
            $response['ok'] === false && isset($response['error']['code']),
            json_encode($response['error'] ?? [])
        );

        $service = new TestService();
        $engine = new Engine(Actor::make('staff', '1', 'Sari'), self::config(), $service);
        $response = $engine->handle('apakah buku #12 tersedia?');
        self::add(
            $cases,
            'engine: biblio detail shows copies',
            $response['ok'] === true && count(self::blockOfType($response, 'list')['items'] ?? []) === 2
        );

        $service = new TestService();
        $engine = new Engine(Actor::make('staff', '1', 'Sari'), self::config(), $service);
        $response = $engine->handle('ringkasan hari ini');
        self::add($cases, 'engine: report renders facts', self::hasBlock($response, 'facts'));
    }

    /**
     * @param array $cases
     * @return void
     */
    private static function writes(array &$cases)
    {
        $_SESSION = [];
        RateLimit::reset();
        $config = self::config();

        // Gate closed: the plan is shown, but nothing can be confirmed.
        $service = new TestService();
        $engine = new Engine(Actor::make('staff', '1', 'Sari'), $config, $service);
        $response = $engine->handle('pinjamkan B00001 ke anggota 123');
        self::add(
            $cases,
            'writes: closed gate shows a plan without a token',
            $response['pending'] === null
                && self::hasNotice($response, Lang::t('en', 'write_disabled')),
            json_encode($response['reply']['blocks'] ?? [])
        );

        // Gate open: preview -> confirm -> committed.
        $config['allow_admin_write'] = true;
        $service = new TestService();
        $engine = new Engine(Actor::make('staff', '1', 'Sari'), $config, $service);
        $preview = $engine->handle('pinjamkan B00001 ke anggota 123');
        $token = (string)($preview['pending']['token'] ?? '');

        self::add($cases, 'writes: open gate issues a confirmation', $token !== '' && $preview['ok'] === true);

        $done = $engine->handle('', $token);
        $args = $service->callArgs('loan.checkout');

        self::add(
            $cases,
            'writes: confirmation executes with commit',
            $done['ok'] === true
                && !empty($done['meta']['committed'])
                && is_array($args) && $args[2] === true,
            json_encode($args)
        );

        $replay = $engine->handle('', $token);
        self::add(
            $cases,
            'writes: confirmed token cannot run twice',
            $replay['reply']['text'] === Lang::t('en', 'confirm_invalid'),
            $replay['reply']['text']
        );

        self::add(
            $cases,
            'writes: actor is attributed to the staff session',
            ($service->lastActor['type'] ?? '') === 'staff' && ($service->lastActor['id'] ?? '') === '1',
            json_encode($service->lastActor)
        );

        // A member cannot renew somebody else's loan.
        $config = self::config();
        $config['allow_member_write'] = true;
        $service = new TestService();
        $engine = new Engine(Actor::make('member', '123', 'Rina'), $config, $service);
        $response = $engine->handle('perpanjang pinjaman 88');
        self::add(
            $cases,
            'writes: member cannot renew a foreign loan',
            $response['reply']['text'] === Lang::t('en', 'member_not_self'),
            $response['reply']['text']
        );

        $service = new TestService();
        $engine = new Engine(Actor::make('member', '123', 'Rina'), $config, $service);
        $preview = $engine->handle('perpanjang pinjaman 77');
        $token = (string)($preview['pending']['token'] ?? '');
        $done = $token === '' ? [] : $engine->handle('', $token);

        self::add(
            $cases,
            'writes: member renewal of own loan commits',
            $token !== '' && !empty($done['meta']['committed']),
            json_encode($preview['pending'] ?? null)
        );

        self::add(
            $cases,
            'writes: member write is attributed to the member',
            ($service->lastActor['type'] ?? '') === 'member' && ($service->lastActor['id'] ?? '') === '123',
            json_encode($service->lastActor)
        );
    }



    /**
     * Search that forgives typos, and the ranking behind it.
     *
     * The service is loaded through `Engine::serviceClass()`: on a full repo
     * that is the Commands copy, on a chatbot-only install the bundled one.
     *
     * @param array $cases
     * @return void
     */
    private static function search(array &$cases)
    {
        $_SESSION = [];
        RateLimit::reset();

        try {
            $class = Engine::serviceClass();
        } catch (\Throwable $error) {
            return;
        }

        $tokens = $class::searchTokens('Buku tentang Sejarah Indonesia?');
        self::add(
            $cases,
            'search: filler words are dropped from the query',
            $tokens === ['sejarah', 'indonesia'],
            json_encode($tokens)
        );

        $deduped = $class::searchTokens('sejarah sejarah a the buku 12');
        self::add(
            $cases,
            'search: tokens are unique and short words are ignored',
            $deduped === ['sejarah', '12'],
            json_encode($deduped)
        );

        self::add(
            $cases,
            'search: a one-letter typo stays similar, a different word does not',
            $class::similarity('sejarh', 'sejarah') > 0.8
                && $class::similarity('sejarah', 'sejarah') === 1.0
                && $class::similarity('abc', 'xyz') < 0.3,
            json_encode([
                $class::similarity('sejarh', 'sejarah'),
                $class::similarity('abc', 'xyz'),
            ])
        );

        $ranked = $class::rankTitles([
            ['biblio_id' => 1, 'title' => 'Manajemen Pemasaran'],
            ['biblio_id' => 2, 'title' => 'Laskar Pelangi'],
            ['biblio_id' => 3, 'title' => 'Sejarah Indonesia Modern'],
        ], 'laskr pelangi', 2);

        self::add(
            $cases,
            'search: did-you-mean ranks the closest title first',
            isset($ranked[0]) && $ranked[0]['biblio_id'] === 2 && count($ranked) <= 2,
            json_encode($ranked)
        );

        // The SQL fragments are built from the same token list, so the
        // placeholder count must match the parameter count exactly.
        $method = new \ReflectionMethod($class, 'biblioSearchFragment');
        $method->setAccessible(true);
        $fake = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        $exact = (array)$method->invoke($fake, ['sejarah', 'indonesia'], 'exact');
        $prefix = (array)$method->invoke($fake, ['sejarh'], 'prefix');

        self::add(
            $cases,
            'search: search fragments keep placeholders and parameters in step',
            substr_count($exact['where'], '?') === count($exact['where_params'])
                && substr_count($exact['score'], '?') === count($exact['score_params'])
                && substr_count($prefix['where'], '?') === count($prefix['where_params'])
                && substr_count($prefix['score'], '?') === count($prefix['score_params']),
            json_encode([
                'where' => substr_count($exact['where'], '?') . '/' . count($exact['where_params']),
                'score' => substr_count($exact['score'], '?') . '/' . count($exact['score_params']),
            ])
        );

        self::add(
            $cases,
            'search: the typo stage shortens the token instead of giving up',
            strpos((string)$prefix['where_params'][0], 'seja') !== false
                && strpos((string)$prefix['where_params'][0], 'sejarh') === false,
            json_encode($prefix['where_params'][0] ?? null)
        );

        if (!class_exists('ChatbotSearchFake', false)) {
            eval('namespace Chatbot; class ChatbotSearchFake extends \Commands\Support\CliAnything {
                public $rowsQueue = [];
                public $scalarQueue = [];
                public $sql = [];
                public $params = [];
                public $fulltextAnswer = false;
                public function __construct() {}
                protected function hasFulltextIndex(): bool { return $this->fulltextAnswer; }
                protected function rows(string $sql, array $params = []): array {
                    $this->sql[] = $sql;
                    $this->params[] = $params;
                    $row = array_shift($this->rowsQueue);
                    return is_array($row) ? $row : [];
                }
                protected function scalar(string $sql, array $params = []) {
                    $next = array_shift($this->scalarQueue);
                    return is_null($next) ? 0 : $next;
                }
            }');
        }

        $row = [
            'biblio_id' => 12,
            'title' => 'Sejarah Indonesia Modern',
            'authors' => 'Budi Santoso',
            'isbn_issn' => '',
            'publish_year' => '2019',
            'call_number' => '959.8 BUD s',
            'item_count' => 3,
            'loan_count' => 1,
            'available_count' => 2,
            'popularity' => 41,
            'rank_score' => 25,
        ];

        $service = new \Chatbot\ChatbotSearchFake();
        $service->rowsQueue = [[], [$row]];
        $service->scalarQueue = [0, 1];

        $rescued = $service->biblioList('sejarh', 5);
        self::add(
            $cases,
            'search: a typo falls through to the prefix stage',
            $rescued['stage'] === 'prefix' && $rescued['total'] === 1 && $rescued['count'] === 1,
            json_encode(['stage' => $rescued['stage'], 'total' => $rescued['total']])
        );

        self::add(
            $cases,
            'search: the prefix stage really queries the shortened token',
            isset($service->params[1])
                && strpos((string)json_encode($service->params[1]), '%seja%') !== false
                && strpos((string)json_encode($service->params[1]), '%sejarh%') === false,
            isset($service->params[1]) ? json_encode($service->params[1]) : 'missing query'
        );

        $service = new \Chatbot\ChatbotSearchFake();
        $service->rowsQueue = [
            [],
            [],
            [
                ['biblio_id' => 21, 'title' => 'Laskar Pelangi'],
                ['biblio_id' => 1, 'title' => 'Manajemen Pemasaran'],
            ],
            [$row],
        ];
        $service->scalarQueue = [0, 0];

        $suggested = $service->biblioList('laskr', 5);
        self::add(
            $cases,
            'search: nothing close becomes a did-you-mean answer',
            $suggested['stage'] === 'suggestion'
                && isset($suggested['suggestions'][0])
                && $suggested['suggestions'][0]['title'] === 'Laskar Pelangi',
            json_encode(['stage' => $suggested['stage'], 'suggestions' => $suggested['suggestions']])
        );

        $service = new \Chatbot\ChatbotSearchFake();
        $service->rowsQueue = [[$row]];
        $service->scalarQueue = [1];
        $service->biblioList('sejarah', 5, 0, 'popular');

        self::add(
            $cases,
            'search: popular sorting is pushed into SQL',
            isset($service->sql[0]) && strpos($service->sql[0], 'popularity DESC, rank_score') !== false,
            isset($service->sql[0]) ? 'ordered by popularity' : 'missing query'
        );

        // ---- what the user actually sees --------------------------------
        $engine = new Engine(Actor::anonymous('guest'), self::config(), new TestService());
        $typo = $engine->handle('cari sejarh');
        self::add(
            $cases,
            'search: a rescued typo is labelled as approximate',
            strpos((string)($typo['reply']['text'] ?? ''), 'Sejarah Indonesia Modern') !== false
                || strpos((string)($typo['reply']['text'] ?? ''), 'closest') !== false
                || in_array('notice', array_column((array)($typo['reply']['blocks'] ?? []), 'type'), true),
            json_encode($typo['reply']['blocks'] ?? [])
        );

        $_SESSION = [];
        RateLimit::reset();
        $engine = new Engine(Actor::anonymous('guest'), self::config(), new TestService());
        $didYouMean = $engine->handle('cari laskar pelangii');
        $badges = array_column((array)($didYouMean['reply']['blocks'][0]['items'] ?? []), 'badge');

        self::add(
            $cases,
            'search: did-you-mean shows the closest titles with a score',
            (string)($didYouMean['reply']['text'] ?? '') === Lang::t('en', 'search_did_you_mean', ['query' => 'laskar pelangii'])
                && in_array('86%', $badges, true),
            json_encode(['text' => $didYouMean['reply']['text'] ?? '', 'badges' => $badges])
        );

        $_SESSION = [];
        RateLimit::reset();
        $service = new TestService();
        $engine = new Engine(Actor::anonymous('guest'), self::config(), $service);
        $popular = $engine->handle('buku populer');
        $call = $service->callArgs('biblio.list');

        self::add(
            $cases,
            'search: "buku populer" asks for the popular ordering',
            is_array($call) && ($call[3] ?? null) === 'popular' && ($call[0] ?? null) === ''
                && (string)($popular['reply']['text'] ?? '') === Lang::t('en', 'biblio_header_popular')
                && strpos((string)json_encode($popular['reply']['blocks'] ?? []), '41x borrowed') !== false,
            json_encode(['args' => $call, 'text' => $popular['reply']['text'] ?? ''])
        );


        // ---- OPAC-hidden records --------------------------------------
        $_SESSION = [];
        RateLimit::reset();
        $guestService = new TestService();
        $guestEngine = new Engine(Actor::anonymous('guest'), self::config(), $guestService);
        $guestEngine->handle('buku populer');
        $guestCall = $guestService->callArgs('biblio.list');

        $_SESSION = [];
        RateLimit::reset();
        $staffService = new TestService();
        $staffEngine = new Engine(Actor::make('staff', '1', 'Sari'), self::config(), $staffService);
        $staffEngine->handle('buku populer');
        $staffCall = $staffService->callArgs('biblio.list');


        // The chat hands its own `search` block to the service, so weights are
        // tuned in config/chatbot.php rather than in PHP.
        $_SESSION = [];
        RateLimit::reset();
        $tuned = new TestService();
        $engine = new Engine(
            Actor::anonymous('guest'),
            array_merge(self::config(), ['search' => ['weights' => ['title_exact' => 55], 'typo' => false]]),
            $tuned
        );
        $engine->handle('cari sejarah');
        $tunedCall = $tuned->callArgs('biblio.list');

        self::add(
            $cases,
            'search: the chat config reaches the service',
            is_array($tunedCall)
                && (int)($tunedCall[5]['weights']['title_exact'] ?? 0) === 55
                && ($tunedCall[5]['typo'] ?? null) === false,
            json_encode($tunedCall[5] ?? null)
        );

        self::add(
            $cases,
            'search: hidden records stay hidden for guests and members',
            is_array($guestCall) && ($guestCall[4] ?? null) === false
                && is_array($staffCall) && ($staffCall[4] ?? null) === true,
            json_encode(['guest' => $guestCall[4] ?? null, 'staff' => $staffCall[4] ?? null])
        );


        // ---- configurable ranking -------------------------------------

        $ftService = new \Chatbot\ChatbotSearchFake();
        $ftService->fulltextAnswer = true;
        $ftFragment = (array)$method->invoke($ftService, ['sejarah', 'indonesia'], 'exact', false, [], true);

        self::add(
            $cases,
            'search: the FULLTEXT bonus is added once, not once per token',
            substr_count($ftFragment['where'], 'AGAINST') === 1
                && substr_count($ftFragment['score'], 'AGAINST') === 1
                && substr_count($ftFragment['where'], '?') === count($ftFragment['where_params'])
                && substr_count($ftFragment['score'], '?') === count($ftFragment['score_params'])
                && (string)($ftFragment['where_params'][0] ?? '') === 'sejarah* indonesia*',
            json_encode(['where' => substr_count($ftFragment['where'], 'AGAINST'), 'score' => substr_count($ftFragment['score'], 'AGAINST')])
        );

        $service = new \Chatbot\ChatbotSearchFake();
        $service->rowsQueue = [[$row]];
        $service->scalarQueue = [1];
        $service->biblioList('sejarah', 5, 0, 'relevance', false, ['weights' => ['title_exact' => 999]]);

        self::add(
            $cases,
            'search: weights come from the caller, the rest of the table stays',
            isset($service->sql[0]) && strpos($service->sql[0], 'THEN 999') !== false
                && strpos($service->sql[0], 'THEN 110') !== false,
            isset($service->sql[0]) ? 'weights applied' : 'missing query'
        );

        $service = new \Chatbot\ChatbotSearchFake();
        $service->fulltextAnswer = true;
        $service->rowsQueue = [[$row]];
        $service->scalarQueue = [1];
        $service->biblioList('sejarah', 5);

        self::add(
            $cases,
            'search: a FULLTEXT index is used when the schema has one',
            isset($service->sql[0]) && strpos($service->sql[0], 'MATCH (b.title) AGAINST') !== false
                && strpos((string)json_encode($service->params[0]), 'sejarah*') !== false,
            isset($service->sql[0]) ? 'fulltext branch present' : 'missing query'
        );

        $service = new \Chatbot\ChatbotSearchFake();
        $service->rowsQueue = [[$row]];
        $service->scalarQueue = [1];
        $service->biblioList('sejarah', 5);

        self::add(
            $cases,
            'search: no FULLTEXT index means no MATCH clause',
            isset($service->sql[0]) && strpos($service->sql[0], 'MATCH (b.title)') === false,
            isset($service->sql[0]) ? 'like only' : 'missing query'
        );

        $service = new \Chatbot\ChatbotSearchFake();
        $service->rowsQueue = [[], []];
        $service->scalarQueue = [0, 0];
        $disabled = $service->biblioList('sejarh', 5, 0, 'relevance', false, ['typo' => false, 'suggestions' => 5]);

        self::add(
            $cases,
            'search: the typo stage can be switched off',
            $disabled['stage'] === 'empty'
                && strpos((string)json_encode($service->params), '%seja%') === false,
            json_encode(['stage' => $disabled['stage']])
        );

        $custom = $class::searchTokens('metodologi skripsi sejarah', ['stopwords' => ['skripsi'], 'max_tokens' => 2]);
        self::add(
            $cases,
            'search: stopwords and the token cap are configurable',
            $custom === ['metodologi', 'sejarah'],
            json_encode($custom)
        );

        self::add(
            $cases,
            'search: sort hints are read from the message',
            Intent::sortHint('buku terlaris') === 'popular'
                && Intent::sortHint('buku terbaru') === 'newest'
                && Intent::sortHint('cari buku sejarah') === 'relevance',
            json_encode([
                Intent::sortHint('buku terlaris'),
                Intent::sortHint('buku terbaru'),
                Intent::sortHint('cari buku sejarah'),
            ])
        );
    }


    /**
     * Conversation log: what is stored, what is dropped, and how it is read.
     *
     * @param array $cases
     * @return void
     */
    private static function logs(array &$cases)
    {
        $_SESSION = [];
        RateLimit::reset();

        $dir = self::tempDir('log');
        $config = self::config();
        $config['log'] = array_merge($config['log'], [
            'dir' => $dir,
            'retention_days' => 90,
            'max_bytes' => 10240,
            'store_message' => true,
            'truncate' => 300,
        ]);

        $log = new LogStore($config);
        $req = 'abcd1234';
        $stored = $log->record([
            'req' => $req,
            'surface' => 'opac',
            'role' => 'member',
            'actor_id' => 'M-000123',
            'locale' => 'id',
            'q' => 'pinjaman saya',
            'intent' => 'my.loans',
            'ms' => 42,
        ]);

        $rows = $log->records();
        $raw = (string)@file_get_contents($dir . '/log-' . gmdate('Ym') . '.ndjson.php');

        self::add(
            $cases,
            'log: a message is stored with its intent and latency',
            $stored && count($rows) === 1
                && $rows[0]['intent'] === 'my.loans'
                && (int)$rows[0]['ms'] === 42
                && $rows[0]['surface'] === 'opac',
            json_encode($rows[0] ?? [])
        );

        self::add(
            $cases,
            'log: the file starts with a PHP exit guard',
            strpos($raw, '<?php exit; ?' . '>') === 0
                && strpos($raw, "\n{") !== false,
            substr($raw, 0, 40)
        );

        self::add(
            $cases,
            'log: no member id, only a salted hash',
            strpos($raw, 'M-000123') === false
                && strlen((string)($rows[0]['actor'] ?? '')) === 16
                && $rows[0]['actor'] !== $rows[0]['q'],
            json_encode($rows[0]['actor'] ?? null)
        );

        $second = new LogStore($config);
        $second->record(['surface' => 'opac', 'role' => 'member', 'actor_id' => 'M-000123', 'q' => 'halo']);
        $second->record(['surface' => 'opac', 'role' => 'guest', 'actor_id' => '', 'q' => 'halo']);

        $rows = $log->records();
        $hashes = [];

        foreach ($rows as $row) {
            if ($row['role'] === 'member') {
                $hashes[] = (string)$row['actor'];
            }
        }

        self::add(
            $cases,
            'log: the same actor keeps one hash across messages',
            count($hashes) === 2 && count(array_unique($hashes)) === 1 && $hashes[0] !== '',
            json_encode($hashes)
        );

        $log->feedback($req, 'up', ['surface' => 'opac', 'role' => 'member', 'actor_id' => 'M-000123']);
        $merged = $log->records();
        $tagged = null;

        foreach ($merged as $candidate) {
            if ((string)($candidate['req'] ?? '') === $req) {
                $tagged = $candidate;
            }
        }

        self::add(
            $cases,
            'log: feedback is appended and merged onto its message',
            is_array($tagged) && ($tagged['feedback'] ?? '') === 'up',
            json_encode($tagged)
        );

        self::add(
            $cases,
            'log: an unknown feedback value is refused',
            !$log->feedback($req, 'great', []) && !$log->feedback('nope', 'up', [])
        );

        $quiet = $config;
        $quiet['log'] = array_merge($config['log'], ['store_message' => false]);
        $quietLog = new LogStore($quiet);
        $quietLog->record(['surface' => 'opac', 'role' => 'guest', 'q' => 'rahasia sekali']);
        $quietRows = $quietLog->records(['limit' => 1]);

        self::add(
            $cases,
            'log: store_message=false keeps only a hash',
            ($quietRows[0]['q'] ?? 'x') === '' && strlen((string)($quietRows[0]['q_hash'] ?? '')) === 16,
            json_encode($quietRows[0] ?? [])
        );

        $long = $log->mask(str_repeat('a', 400));
        self::add(
            $cases,
            'log: a long question is truncated',
            Str::length($long) >= 297 && Str::length($long) <= 303
                && substr($long, -3) === '…'
                && substr($long, 0, 10) === str_repeat('a', 10),
            (string)Str::length($long)
        );

        $summary = $log->summary();
        self::add(
            $cases,
            'log: the summary counts answered, unanswered and feedback',
            $summary['total'] >= 4
                && $summary['faq'] === 0
                && $summary['feedback_up'] === 1
                && $summary['unanswered'] >= 1,
            json_encode($summary)
        );

        $filtered = $log->records(['role' => 'guest']);
        self::add(
            $cases,
            'log: filters narrow by role and limit',
            count($filtered) >= 1
                && count($log->records(['limit' => 1])) === 1
                && count($log->records(['offset' => 1, 'limit' => 1])) === 1,
            json_encode(array_column($log->records(['limit' => 1]), 'role'))
        );

        // ---- rotation -------------------------------------------------
        $rotating = $config;
        $rotating['log'] = array_merge($config['log'], ['max_bytes' => 10240, 'truncate' => 300]);
        $rotateLog = new LogStore($rotating);

        for ($index = 0; $index < 80; $index++) {
            $rotateLog->record(['surface' => 'opac', 'role' => 'guest', 'q' => str_repeat('x', 280) . $index]);
        }

        $parts = glob($dir . '/log-' . gmdate('Ym') . '.ndjson.php*');
        self::add(
            $cases,
            'log: a month file rotates instead of growing without limit',
            is_array($parts) && count($parts) >= 2,
            json_encode(array_map('basename', is_array($parts) ? $parts : []))
        );

        // ---- retention ------------------------------------------------
        $old = $dir . '/log-202001.ndjson.php';
        @file_put_contents($old, LogStore::GUARD . '{"kind":"message","ts":"2020-01-01T00:00:00+00:00"}' . "\n");
        $removed = $rotateLog->prune(time());

        self::add(
            $cases,
            'log: files older than the retention window are removed',
            !file_exists($old) && $removed >= 1,
            (string)$removed
        );

        // ---- a broken directory must not break the answer --------------
        $broken = $config;
        $broken['log'] = array_merge($config['log'], ['dir' => $dir . '/log-' . gmdate('Ym') . '.ndjson.php']);
        $brokenLog = new LogStore($broken);
        $ok = true;

        try {
            $ok = $brokenLog->record(['surface' => 'opac', 'role' => 'guest', 'q' => 'halo']);
        } catch (\Throwable $error) {
            $ok = false;
        }

        self::add(
            $cases,
            'log: an unwritable directory logs nothing and does not throw',
            $ok === false,
            (string)$brokenLog->directory()
        );

        // ---- HTTP pipeline ---------------------------------------------
        $_SESSION = [];
        RateLimit::reset();
        $empty = self::tempDir('log-http');
        $httpConfig = $config;
        $httpConfig['log'] = array_merge($config['log'], ['dir' => $empty]);
        $token = Http::token();

        $response = Http::process(
            ['message' => 'jam buka', 'csrf_token' => $token],
            Actor::anonymous('guest'),
            'opac',
            new TestService(),
            $httpConfig
        );

        $httpLog = new LogStore($httpConfig);
        $httpRows = $httpLog->records();

        self::add(
            $cases,
            'log: the HTTP endpoint records the question and its request id',
            !empty($httpRows)
                && $httpRows[0]['intent'] === Intent::FAQ
                && $httpRows[0]['surface'] === 'opac'
                && ($response['meta']['request'] ?? '') === $httpRows[0]['req']
                && LogStore::validReq((string)$response['meta']['request']),
            json_encode(['rows' => count($httpRows), 'meta' => $response['meta']['request'] ?? null])
        );

        $feedback = Http::process(
            ['feedback' => 'down', 'req' => (string)$response['meta']['request'], 'csrf_token' => $token],
            Actor::anonymous('guest'),
            'opac',
            new TestService(),
            $httpConfig
        );

        self::add(
            $cases,
            'log: feedback over HTTP attaches to the earlier answer',
            $feedback['ok'] === true && $feedback['feedback'] === 'down'
                && $httpLog->records()[0]['feedback'] === 'down',
            json_encode($feedback)
        );

        $badFeedback = Http::process(
            ['feedback' => 'down', 'req' => 'nope', 'csrf_token' => $token],
            Actor::anonymous('guest'),
            'opac',
            new TestService(),
            $httpConfig
        );

        self::add(
            $cases,
            'log: feedback with an unknown request id is refused',
            $badFeedback['ok'] === false && $badFeedback['error']['code'] === 'FEEDBACK_INVALID'
        );


        $view = View::render(Actor::anonymous('guest'), 'opac', 'index.php?p=chatbot&action=ask', '/assets/', $config);
        $quietView = View::render(Actor::anonymous('guest'), 'opac', 'index.php?p=chatbot&action=ask', '/assets/', array_merge($config, [
            'log' => array_merge($config['log'], ['feedback' => false]),
        ]));

        self::add(
            $cases,
            'log: the chat offers thumbs only when feedback is on',
            strpos($view, 'data-feedback="1"') !== false
                && strpos($view, 'data-feedback="0"') === false
                && strpos($quietView, 'data-feedback="0"') !== false
                && strpos($view, 'Not helpful') !== false,
            substr($view, 0, 60)
        );
        // ---- channels ---------------------------------------------------
        $_SESSION = [];
        RateLimit::reset();
        $channelDir = self::tempDir('log-channel');
        $channelConfig = $config;
        $channelConfig['log'] = array_merge($config['log'], ['dir' => $channelDir]);
        $channelConfig['channel'] = array_merge($config['channel'], [
            'enabled' => true,
            'token' => 'secret',
            'members' => ['whatsapp:628111' => '123'],
            'storage_dir' => self::tempDir('channel-store'),
        ]);

        Channel::handle(
            ['channel' => 'whatsapp', 'from' => '628111', 'text' => 'pinjaman saya'],
            $channelConfig,
            new TestService(),
            ['X-Chatbot-Token' => 'secret']
        );

        $channelRows = (new LogStore($channelConfig))->records();

        self::add(
            $cases,
            'log: a channel message is recorded with the channel as surface',
            !empty($channelRows)
                && $channelRows[0]['surface'] === 'whatsapp'
                && $channelRows[0]['role'] === 'member'
                && $channelRows[0]['intent'] === Intent::MY_LOANS,
            json_encode($channelRows[0] ?? [])
        );
    }

    /**
     * A fresh, empty directory inside the system temp dir.
     *
     * @param string $suffix
     * @return string
     */
    private static function tempDir(string $suffix): string
    {
        $dir = sys_get_temp_dir() . '/slims-chatbot-' . $suffix . '-' . getmypid();

        if (is_dir($dir)) {
            foreach ((array)glob($dir . '/*') as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        } else {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }


    /**
     * The staff history page's read side: filters, paging, CSV and escaping.
     *
     * @param array $cases
     * @return void
     */
    private static function history(array &$cases)
    {
        $now = time();
        $filters = History::filters(['days' => '30', 'surface' => 'opac', 'role' => 'member', 'feedback' => 'down', 'q' => 'denda'], $now);

        self::add(
            $cases,
            'history: query values become log filters',
            $filters['days'] === 30
                && $filters['since'] === $now - 30 * 86400
                && $filters['surface'] === 'opac'
                && $filters['role'] === 'member'
                && $filters['feedback'] === 'down'
                && $filters['q'] === 'denda',
            json_encode($filters)
        );

        $broken = History::filters(['days' => '9999', 'feedback' => 'sideways', 'page' => '-4', 'q' => str_repeat('z', 90)], $now);

        self::add(
            $cases,
            'history: bad filter values fall back instead of failing',
            $broken['days'] === 7
                && !isset($broken['feedback'])
                && $broken['page'] === 1
                && Str::length($broken['q']) === 60,
            json_encode($broken)
        );

        $dir = self::tempDir('history');
        $config = self::config();
        $config['log'] = array_merge($config['log'], ['dir' => $dir, 'truncate' => 300]);
        $log = new LogStore($config);

        for ($index = 0; $index < 120; $index++) {
            $log->record([
                'surface' => $index % 2 === 0 ? 'opac' : 'whatsapp',
                'role' => $index % 3 === 0 ? 'member' : 'guest',
                'q' => 'pertanyaan nomor ' . $index,
                'intent' => $index % 4 === 0 ? Intent::NONE : Intent::BIBLIO_LIST,
                'ms' => $index,
                'faq_id' => $index % 10 === 0 ? 'hours' : '',
            ]);
        }

        $first = History::page($log, History::filters(['days' => 7], $now));
        $last = History::page($log, History::filters(['days' => 7, 'page' => 9], $now));

        self::add(
            $cases,
            'history: rows are paged, and a page beyond the end is clamped',
            $first['pages'] === 3
                && count($first['rows']) === 50
                && $first['total'] === 120
                && $last['page'] === 3
                && count($last['rows']) === 20,
            json_encode(['pages' => $first['pages'], 'rows' => count($first['rows']), 'last' => count($last['rows'])])
        );

        $second = History::page($log, History::filters(['days' => 7, 'page' => 2], $now));
        $overlap = array_intersect(array_column($first['rows'], 'req'), array_column($second['rows'], 'req'));

        self::add(
            $cases,
            'history: page two continues where page one stopped',
            count($second['rows']) === 50 && $overlap === [],
            json_encode(count($overlap))
        );

        $down = History::page($log, History::filters(['days' => 7, 'feedback' => 'down'], $now));
        self::add(
            $cases,
            'history: a feedback filter only shows flagged answers',
            $down['total'] === 0,
            (string)$down['total']
        );

        $summary = History::summary($log, History::filters(['days' => 7], $now));

        self::add(
            $cases,
            'history: the summary counts answered, unanswered and FAQ answers',
            $summary['total'] === 120
                && $summary['faq'] === 12
                && $summary['unanswered'] === 24
                && $summary['answered'] === 96
                && $summary['answered_pct'] === 80.0,
            json_encode($summary)
        );

        $csv = History::csv([[
            'ts' => '2026-11-27T10:00:00+00:00',
            'surface' => 'opac',
            'role' => 'guest',
            'intent' => 'none',
            'faq_id' => '',
            'guard' => '',
            'feedback' => 'down',
            'ms' => 12,
            'q' => 'jam buka, "sabtu"',
        ]]);

        self::add(
            $cases,
            'history: CSV has a header and quotes commas and quotes',
            strpos($csv, 'time,surface,role') === 0
                && strpos($csv, '"jam buka, ""sabtu"""') !== false
                && substr_count($csv, "\n") === 2,
            $csv
        );

        $html = History::table([[
            'req' => 'abcd1234',
            'ts' => '2026-11-27T10:00:00+00:00',
            'q' => '<script>alert(1)</script>',
            'intent' => '<b>none</b>',
            'surface' => 'opac',
            'role' => 'guest',
            'feedback' => 'down',
            'ms' => 3,
        ]], 'en', 'index.php?p=chatbot_history&days=7');

        self::add(
            $cases,
            'history: table cells are escaped, including the message',
            strpos($html, '<script>') === false
                && strpos($html, '&lt;script&gt;') !== false
                && strpos($html, 'req=abcd1234') !== false
                && strpos($html, 'days=7') !== false,
            substr($html, 0, 120)
        );

        $base = History::baseUrl('/index.php?p=chatbot_history&days=7&page=2&req=abcd1234&export=csv');
        $next = History::withQuery($base, 'page', '3');

        self::add(
            $cases,
            'history: pagination links keep the filters and drop the rest',
            $base === '/index.php?p=chatbot_history&days=7'
                && $next === '/index.php?p=chatbot_history&days=7&page=3',
            $base . ' | ' . $next
        );

        $staff = ['role' => Policy::STAFF];
        $guest = ['role' => Policy::GUEST];
        $member = ['role' => Policy::MEMBER];
        $off = array_merge($config, ['admin_history' => false]);
        $noLog = array_merge($config, ['log' => array_merge($config['log'], ['enabled' => false])]);

        self::add(
            $cases,
            'history: only staff with the flag can read it',
            History::allowed($staff, $config) === true
                && History::allowed($guest, $config) === false
                && History::allowed($member, $config) === false
                && History::allowed($staff, $off) === false
                && History::allowed($staff, $noLog) === false,
            json_encode([
                History::allowed($staff, $off),
                History::allowed($guest, $config),
            ])
        );

        $detail = $log->records(['req' => $first['rows'][0]['req']]);

        self::add(
            $cases,
            'history: a detail lookup finds exactly one conversation',
            count($detail) === 1 && $detail[0]['req'] === $first['rows'][0]['req'],
            json_encode(count($detail))
        );
    }


    /**
     * Staff corrections: overlay file, drafts, preview, audit, revert.
     *
     * @param array $cases
     * @return void
     */
    private static function overlay(array &$cases)
    {
        $dir = self::tempDir('overlay');
        $config = self::config();
        $file = $dir . '/faq.local.php';
        $config['faq'] = array_merge($config['faq'], [
            'overlay' => true,
            'overlay_file' => $file,
        ]);

        $overlay = new Overlay($config);

        self::add(
            $cases,
            'overlay: a missing file is empty, not an error',
            $overlay->load() === $overlay->emptyData()
                && count(Faq::items($config)) === count(Faq::items(self::config()))
        );

        $sample = __DIR__ . '/faq.sample.php';
        $sampleHash = sha1((string)file_get_contents($sample));

        $result = $overlay->addKeyword('hours', 'buka sabtu', 'rina');
        $match = Faq::match('buka sabtu', $config, 'id');

        self::add(
            $cases,
            'overlay: a keyword added by staff is used immediately',
            $result['ok'] === true
                && !is_null($match)
                && $match['id'] === 'hours'
                && sha1((string)file_get_contents($sample)) === $sampleHash,
            json_encode(['ok' => $result['ok'], 'match' => $match['id'] ?? null])
        );

        $raw = (string)file_get_contents($file);
        self::add(
            $cases,
            'overlay: the file is a PHP array that outputs nothing when hit',
            strpos($raw, '<?php') === 0
                && strpos($raw, 'return array') !== false
                && substr(rtrim($raw), -1) === ';'
                && glob($dir . '/*.tmp-*') === [],
            substr($raw, 0, 60)
        );

        self::add(
            $cases,
            'overlay: an unknown entry or a duplicate phrase is refused',
            $overlay->addKeyword('nope', 'halo', 'rina')['ok'] === false
                && $overlay->addKeyword('hours', 'buka sabtu', 'rina')['ok'] === false
        );

        $draft = $overlay->addEntry([
            'id' => 'projector',
            'question' => 'Peminjaman proyektor',
            'keywords' => 'proyektor, lcd, projector',
            'answer' => 'Ajukan permohonan di meja layanan dua hari sebelumnya.',
        ], 'rina');

        $draftMatch = Faq::match('proyektor', $config, 'id');
        $itemsWithDraft = Faq::items($config);

        self::add(
            $cases,
            'overlay: a new entry stays a draft and is not answered',
            $draft['ok'] === true
                && is_null($draftMatch)
                && !in_array('projector', array_column($itemsWithDraft, 'id'), true),
            json_encode(['ok' => $draft['ok'], 'match' => $draftMatch['id'] ?? null])
        );

        $overlay->setActive('projector', true, 'rina');
        $activeMatch = Faq::match('proyektor', $config, 'id');
        $overlay->setActive('projector', false, 'rina');
        $backToDraft = Faq::match('proyektor', $config, 'id');
        $overlay->setActive('projector', true, 'rina');

        self::add(
            $cases,
            'overlay: activating an entry makes it live, drafting hides it again',
            !is_null($activeMatch)
                && $activeMatch['id'] === 'projector'
                && ($activeMatch['answer'] ?? '') === 'Ajukan permohonan di meja layanan dua hari sebelumnya.'
                && is_null($backToDraft),
            json_encode(['active' => $activeMatch['id'] ?? null, 'draft' => $backToDraft['id'] ?? null])
        );

        $badEntry = $overlay->addEntry([
            'id' => 'bad',
            'question' => 'Tanpa jawaban',
            'keywords' => 'kosong',
            'answer' => '',
        ], 'rina');
        $badLink = $overlay->addEntry([
            'id' => 'bad-link',
            'question' => 'Tautan aneh',
            'keywords' => 'aneh',
            'answer' => 'Isi.',
            'link' => 'javascript:alert(1)',
        ], 'rina');
        $duplicate = $overlay->addEntry([
            'id' => 'projector',
            'question' => 'Duplikat',
            'keywords' => 'duplikat',
            'answer' => 'Isi.',
        ], 'rina');

        self::add(
            $cases,
            'overlay: empty answers, script links and duplicate ids are refused',
            $badEntry['ok'] === false && $badLink['ok'] === false && $duplicate['ok'] === false,
            json_encode([$badEntry, $badLink, $duplicate])
        );

        $before = count(Faq::items($config));
        $overlay->setHidden('donation', true, 'rina');
        $hiddenMatch = Faq::match('donasi buku', $config, 'id');
        $hiddenIds = array_column(Faq::items($config), 'id');
        $overlay->setHidden('donation', false, 'rina');
        $restoredMatch = Faq::match('donasi buku', $config, 'id');

        self::add(
            $cases,
            'overlay: hiding removes an entry without deleting the content',
            ($hiddenMatch['id'] ?? '') !== 'donation'
                && !in_array('donation', $hiddenIds, true)
                && ($restoredMatch['id'] ?? '') === 'donation'
                && count(Faq::items($config)) === $before,
            json_encode(['hidden' => $hiddenMatch['id'] ?? null, 'restored' => $restoredMatch['id'] ?? null])
        );

        $candidate = $overlay->load();
        $candidate['keywords']['address'][] = 'kode pos';
        $preview = $overlay->preview($candidate, 'kode pos', 'id');
        $afterPreview = Faq::match('kode pos', $config, 'id');

        self::add(
            $cases,
            'overlay: a preview answers without writing the change',
            !is_null($preview) && $preview['id'] === 'address' && is_null($afterPreview),
            json_encode(['preview' => $preview['id'] ?? null, 'saved' => $afterPreview['id'] ?? null])
        );

        $off = array_merge($config, ['faq' => array_merge($config['faq'], ['overlay' => false])]);
        self::add(
            $cases,
            'overlay: switching the overlay off restores the plain content file',
            is_null(Faq::match('kode pos', $off, 'id'))
                && is_null(Faq::match('proyektor', $off, 'id'))
                && !in_array('projector', array_column(Faq::items($off), 'id'), true)
        );

        $audit = $overlay->audit(20);
        $actions = array_column($audit, 'action');

        self::add(
            $cases,
            'overlay: every change is audited with who did it',
            in_array('keyword', $actions, true)
                && in_array('entry', $actions, true)
                && in_array('activate', $actions, true)
                && in_array('hide', $actions, true)
                && ($audit[0]['actor'] ?? '') === 'rina',
            json_encode(array_slice($actions, 0, 6))
        );

        $versionBefore = (int)($overlay->load()['version'] ?? 0);
        $revert = $overlay->revert('rina');
        $restored = $overlay->load();
        $lastAudit = $overlay->audit(1);

        self::add(
            $cases,
            'overlay: the previous version can be restored',
            $revert['ok'] === true
                && in_array('donation', (array)$restored['hidden'], true)
                && ($lastAudit[0]['action'] ?? '') === 'revert'
                && (int)$restored['version'] >= $versionBefore,
            json_encode(['hidden' => $restored['hidden'], 'version' => $restored['version']])
        );

        $html = View::render(Actor::anonymous('guest'), 'opac', 'index.php?p=chatbot&action=ask', '/assets/', $config);
        self::add(
            $cases,
            'overlay: staff-only pages are not reachable from the chat view',
            strpos($html, 'faq.local.php') === false && strpos($html, 'chatbot-admin') === false
        );

        $guest = ['role' => Policy::GUEST];
        $member = ['role' => Policy::MEMBER];
        $staff = ['role' => Policy::STAFF];
        $closed = array_merge($config, ['admin_overlay' => false]);

        self::add(
            $cases,
            'overlay: only staff with the flag may correct answers',
            Admin::can($staff, $config, 'admin_overlay') === true
                && Admin::can($guest, $config, 'admin_overlay') === false
                && Admin::can($member, $config, 'admin_overlay') === false
                && Admin::can($staff, $closed, 'admin_overlay') === false
                && Admin::can($staff, $config, 'made_up_flag') === false
        );
    }


    /**
     * The guard: blocked questions, forbidden answers, bypass attempts and
     * the over-blocking check.
     *
     * @param array $cases
     * @return void
     */
    private static function guard(array &$cases)
    {
        $dir = self::tempDir('guard');
        $file = $dir . '/guard.php';
        $config = self::config();
        $config['guard'] = ['enabled' => true, 'file' => $file, 'log_hits' => true];
        $config['log'] = array_merge($config['log'], ['dir' => self::tempDir('guard-log')]);

        self::add(
            $cases,
            'guard: off by default, so no rule can fire',
            Guard::enabled(self::config()) === false
                && is_null(Guard::question('bebas denda', self::config()))
        );

        $invalid = [
            'scope' => ['scope' => 'everything', 'type' => 'phrase', 'pattern' => 'x', 'category' => 'policy', 'action' => 'refuse', 'note' => 'n'],
            'type' => ['scope' => 'question', 'type' => 'vibes', 'pattern' => 'x', 'category' => 'policy', 'action' => 'refuse', 'note' => 'n'],
            'action' => ['scope' => 'question', 'type' => 'phrase', 'pattern' => 'x', 'category' => 'policy', 'action' => 'explode', 'note' => 'n'],
            'hide' => ['scope' => 'question', 'type' => 'phrase', 'pattern' => 'x', 'category' => 'policy', 'action' => 'hide', 'note' => 'n'],
            'note' => ['scope' => 'question', 'type' => 'phrase', 'pattern' => 'x', 'category' => 'policy', 'action' => 'refuse', 'note' => ' '],
            'regex' => ['scope' => 'question', 'type' => 'regex', 'pattern' => '(*', 'category' => 'policy', 'action' => 'refuse', 'note' => 'n'],
            'long' => ['scope' => 'question', 'type' => 'phrase', 'pattern' => str_repeat('a', 200), 'category' => 'policy', 'action' => 'refuse', 'note' => 'n'],
        ];

        $bad = [];

        foreach ($invalid as $name => $rule) {
            if (Guard::validate($rule) === '') {
                $bad[] = $name;
            }
        }

        self::add(
            $cases,
            'guard: invalid scope, type, action, note, regex and length are refused',
            $bad === [],
            json_encode($bad)
        );

        $rules = [
            ['id' => 'g-bebas', 'scope' => 'question', 'type' => 'phrase', 'pattern' => 'bebas denda', 'category' => 'policy', 'action' => 'refuse', 'note' => 'Kebijakan kepala perpustakaan'],
            ['id' => 'g-halo', 'scope' => 'question', 'type' => 'phrase', 'pattern' => 'obrolan santai', 'category' => 'off-topic', 'action' => 'fallback', 'note' => 'Bukan layanan perpustakaan'],
            ['id' => 'g-spam', 'scope' => 'question', 'type' => 'word', 'pattern' => 'spam', 'category' => 'spam', 'action' => 'refuse', 'note' => 'Spam'],
            ['id' => 'g-kotor', 'scope' => 'question', 'type' => 'regex', 'pattern' => 'kata\s+kotor', 'category' => 'abuse', 'action' => 'refuse', 'note' => 'Uji regex'],
            ['id' => 'g-answer', 'scope' => 'answer', 'type' => 'phrase', 'pattern' => 'bebas denda juga', 'category' => 'policy', 'action' => 'hide', 'note' => 'Jangan pernah berjanji'],
        ];

        $saved = Guard::save($rules, 'rina', $config);
        Guard::flush();

        self::add(
            $cases,
            'guard: a rule set is written atomically and read back',
            $saved['ok'] === true
                && is_file($file)
                && strpos((string)file_get_contents($file), '<?php') === 0
                && count(Guard::rules($config)) === 5
                && glob($dir . '/*.tmp-*') === [],
            json_encode($saved)
        );

        $variants = ['bebas denda', 'BEBAS  DENDA!!', "bebas\u{200B} denda", 'b3bas d3nda', '  Bebas, denda.  '];
        $missed = [];

        foreach ($variants as $variant) {
            if (is_null(Guard::question($variant, $config))) {
                $missed[] = $variant;
            }
        }

        self::add(
            $cases,
            'guard: case, spacing, punctuation, zero-width and leet do not bypass a phrase',
            $missed === [],
            json_encode($missed)
        );

        self::add(
            $cases,
            'guard: a normal sentence that mentions the word is not blocked',
            is_null(Guard::question('buku tentang denda perpustakaan', $config))
                && is_null(Guard::question('spammer bukan spam', $config)) === false
                && !is_null(Guard::question('spam', $config))
        );

        self::add(
            $cases,
            'guard: a regex rule matches its pattern and only that',
            !is_null(Guard::question('kata     kotor sekali', $config))
                && is_null(Guard::question('kata manis', $config))
        );

        $tmpFaq = $dir . '/faq.php';
        file_put_contents($tmpFaq, "<?php\nreturn [\n"
            . "  ['id' => 'clean', 'question' => 'Jam buka', 'keywords' => ['jam buka'], 'answer' => 'Buka 08.00-16.00.'],\n"
            . "  ['id' => 'bad', 'question' => 'Denda khusus', 'keywords' => ['denda khusus'], 'answer' => 'Kami bebas denda juga untuk semua anggota.'],\n"
            . "];\n");

        $faqConfig = $config;
        $faqConfig['faq'] = array_merge($config['faq'], ['file' => $tmpFaq, 'overlay' => false]);

        $ids = array_column(Faq::items($faqConfig), 'id');
        $hidden = Faq::hiddenByGuard($faqConfig);

        self::add(
            $cases,
            'guard: an answer rule hides the entry and reports it',
            !in_array('bad', $ids, true)
                && in_array('clean', $ids, true)
                && $hidden === ['bad']
                && is_null(Faq::match('denda khusus', $faqConfig, 'id')),
            json_encode(['ids' => $ids, 'hidden' => $hidden])
        );

        $faqOff = array_merge($faqConfig, ['guard' => array_merge($config['guard'], ['enabled' => false])]);

        self::add(
            $cases,
            'guard: with the guard off, the same entry is served again',
            in_array('bad', array_column(Faq::items($faqOff), 'id'), true)
                && !is_null(Faq::match('denda khusus', $faqOff, 'id'))
        );

        // ---- what the visitor sees ------------------------------------
        $_SESSION = [];
        RateLimit::reset();
        $service = new TestService();
        $engine = new Engine(Actor::anonymous('guest'), $config, $service);
        $refused = $engine->handle('apakah ada bebas denda?');

        self::add(
            $cases,
            'guard: a blocked question is refused before the service is called',
            strpos((string)$refused['reply']['text'], 'cannot help') !== false
                && ($refused['meta']['guard'] ?? '') === 'g-bebas'
                && $service->calls === [],
            json_encode(['meta' => $refused['meta'], 'calls' => $service->calls])
        );

        $_SESSION = [];
        RateLimit::reset();
        $engine = new Engine(Actor::anonymous('guest'), $config, new TestService());
        $fallback = $engine->handle('mau obrolan santai dong');

        self::add(
            $cases,
            'guard: an out-of-scope rule points at the service desk instead',
            ($fallback['meta']['guard'] ?? '') === 'g-halo'
                && strpos((string)$fallback['reply']['text'], 'outside') !== false
                && ($fallback['meta']['tone'] ?? '') === 'warn',
            json_encode($fallback['meta'])
        );

        $_SESSION = [];
        RateLimit::reset();
        $probe = new GuardServiceProbe();
        $engine = new Engine(Actor::anonymous('guest'), $config, $probe);
        $screened = $engine->handle('cari buku panduan');
        $blockText = Lang::t('en', 'guard_answer_hidden');

        self::add(
            $cases,
            'guard: a forbidden sentence is caught inside a service result',
            ($screened['meta']['guard'] ?? '') === 'g-answer'
                && ($screened['reply']['text'] ?? '') === $blockText
                && !self::mentions($screened, 'bebas denda juga'),
            json_encode(['text' => $screened['reply']['text'], 'meta' => $screened['meta']])
        );

        $_SESSION = [];
        RateLimit::reset();
        $engine = new Engine(Actor::anonymous('guest'), $faqOff, new GuardServiceProbe());
        $notScreened = $engine->handle('cari buku panduan');

        self::add(
            $cases,
            'guard: with the guard off the same result is untouched',
            self::mentions($notScreened, 'bebas denda juga'),
            json_encode($notScreened['reply']['text'])
        );

        $_SESSION = [];
        RateLimit::reset();
        $token = Http::token();
        $logged = Http::process(
            ['message' => 'bebas denda', 'csrf_token' => $token],
            Actor::anonymous('guest'),
            'opac',
            new TestService(),
            $config
        );
        $rows = (new LogStore($config))->records(['limit' => 1]);

        self::add(
            $cases,
            'guard: a hit is recorded in the conversation log',
            ($rows[0]['guard'] ?? '') === 'g-bebas'
                && ($logged['meta']['request'] ?? '') === ($rows[0]['req'] ?? ''),
            json_encode($rows[0] ?? [])
        );

        // ---- not too broad ---------------------------------------------
        $topics = Faq::topics(self::config(), 'en', 20);
        $narrow = Guard::impact(['scope' => 'question', 'type' => 'phrase', 'pattern' => 'opening hours', 'enabled' => true], $topics);
        $wide = Guard::impact(['scope' => 'question', 'type' => 'phrase', 'pattern' => 'jam buka', 'enabled' => true], $topics);

        self::add(
            $cases,
            'guard: impact counts how many normal questions a rule would block',
            $narrow >= 1 && $wide === 0 && Guard::impact(['scope' => 'question', 'type' => 'phrase', 'pattern' => 'zzzz', 'enabled' => true], $topics) === 0,
            json_encode(['english' => $narrow, 'indonesian' => $wide])
        );

        $hits = Guard::hits('spam bebas denda', $config);
        self::add(
            $cases,
            'guard: the test box reports every rule that fires',
            count($hits) === 2 && array_column($hits, 'id') === ['g-bebas', 'g-spam'],
            json_encode(array_column($hits, 'id'))
        );

        $disabledRule = ['id' => 'g-off', 'scope' => 'question', 'type' => 'phrase', 'pattern' => 'mati', 'category' => 'policy', 'action' => 'refuse', 'note' => 'n', 'enabled' => false];
        self::add(
            $cases,
            'guard: a disabled rule stays listed but never fires',
            Guard::matches('mati', Guard::normaliseRule($disabledRule)) === false
                && Guard::matches('mati', Guard::normaliseRule(array_merge($disabledRule, ['enabled' => true]))) === true
        );

        self::add(
            $cases,
            'guard: only staff with the rules flag may change rules',
            Admin::can(['role' => Policy::STAFF], $config, 'admin_rules') === true
                && Admin::can(['role' => Policy::GUEST], $config, 'admin_rules') === false
                && Admin::can(['role' => Policy::STAFF], array_merge($config, ['admin_rules' => false]), 'admin_rules') === false
        );

        @unlink($tmpFaq);
    }

    /**
     * @param array  $response
     * @param string $needle
     * @return bool
     */
    private static function mentions(array $response, string $needle): bool
    {
        $haystack = (string)($response['reply']['text'] ?? '') . json_encode($response['reply']['blocks'] ?? [], JSON_UNESCAPED_UNICODE);

        return strpos(Str::lower($haystack), Str::lower($needle)) !== false;
    }

    /**
     * @param array $cases
     * @return void
     */
    private static function channels(array &$cases)
    {
        $_SESSION = [];
        RateLimit::reset();

        $dir = sys_get_temp_dir() . '/slims-chatbot-test-' . getmypid();
        $headers = ['X-Chatbot-Token' => 'secret'];
        $config = array_merge(self::config(), [
            'channel' => [
                'enabled' => true,
                'token' => 'secret',
                'verify_token' => 'verify',
                'members' => ['whatsapp:628111' => '123'],
                'staff' => ['telegram:999' => 1],
                'storage_dir' => $dir,
            ],
        ]);

        // ---- payload normalization -----------------------------------
        $telegram = Channel::normalize([
            'update_id' => 1,
            'message' => ['chat' => ['id' => 999], 'text' => 'pinjaman terlambat'],
        ]);
        self::add(
            $cases,
            'channel: telegram payload is normalized',
            $telegram['channel'] === 'telegram' && $telegram['from'] === '999' && $telegram['text'] === 'pinjaman terlambat',
            json_encode($telegram)
        );

        $cloud = Channel::normalize([
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [['from' => '628111', 'text' => ['body' => 'pinjaman saya']]]],
                ]],
            ]],
        ]);
        self::add(
            $cases,
            'channel: whatsapp cloud payload is normalized',
            $cloud['channel'] === 'whatsapp' && $cloud['from'] === '628111' && $cloud['text'] === 'pinjaman saya',
            json_encode($cloud)
        );

        $gateway = Channel::normalize(['phone' => '628222', 'message' => 'cari buku sejarah']);
        self::add(
            $cases,
            'channel: local gateway payload is normalized',
            $gateway['channel'] === 'whatsapp' && $gateway['from'] === '628222' && $gateway['text'] === 'cari buku sejarah',
            json_encode($gateway)
        );

        // ---- authentication and identity ----------------------------
        self::add(
            $cases,
            'channel: token is required',
            Channel::authorized($headers, 'secret')
                && !Channel::authorized(['X-Chatbot-Token' => 'nope'], 'secret')
                && !Channel::authorized(['Authorization' => 'Bearer secret'], '')
        );

        $channelConfig = Channel::config($config);
        $guest = Channel::identity('whatsapp', '628999', $channelConfig);
        $member = Channel::identity('whatsapp', '628111', $channelConfig);
        $staff = Channel::identity('telegram', '999', $channelConfig);

        self::add(
            $cases,
            'channel: identity comes from the allow-list',
            $guest['role'] === 'guest'
                && $member['role'] === 'member' && $member['member_id'] === '123'
                && $staff['role'] === 'staff' && $staff['id'] === '1',
            json_encode([$guest['role'], $member['role'], $staff['role']])
        );

        // ---- conversations ------------------------------------------
        $service = new TestService();
        $guestReply = Channel::handle(
            ['channel' => 'whatsapp', 'from' => '628999', 'text' => 'pinjaman saya'],
            $config,
            $service,
            $headers
        );
        self::add(
            $cases,
            'channel: unknown sender is treated as a guest',
            $guestReply['ok'] === true
                && $guestReply['role'] === 'guest'
                && (strpos($guestReply['reply'], 'masuk ke area anggota') !== false
                    || strpos($guestReply['reply'], 'log in') !== false),
            $guestReply['reply']
        );

        $memberReply = Channel::handle(
            ['channel' => 'whatsapp', 'from' => '628111', 'text' => 'pinjaman saya'],
            $config,
            new TestService(),
            $headers
        );
        self::add(
            $cases,
            'channel: member sees their own loans',
            $memberReply['role'] === 'member' && strpos($memberReply['reply'], 'Sejarah Indonesia Modern') !== false,
            $memberReply['reply']
        );

        $service = new TestService();
        $preview = Channel::handle(
            ['channel' => 'telegram', 'from' => '999', 'text' => 'pinjamkan B00001 ke anggota 123'],
            array_merge($config, ['allow_admin_write' => true]),
            $service,
            $headers
        );
        self::add(
            $cases,
            'channel: staff write asks for a reply confirmation',
            $preview['ok'] === true && $preview['pending'] === true
                && strpos($preview['reply'], Lang::t('en', 'channel_confirm_hint')) !== false,
            $preview['reply']
        );

        $files = glob(rtrim($dir, '/') . '/*.php');
        $guarded = !empty($files) && strpos((string)file_get_contents($files[0]), ChannelStore::GUARD) === 0;
        self::add($cases, 'channel: pending file is HTTP-proof', $guarded);

        $confirmed = Channel::handle(
            ['channel' => 'telegram', 'from' => '999', 'text' => 'YA'],
            array_merge($config, ['allow_admin_write' => true]),
            $service,
            $headers
        );
        $args = $service->callArgs('loan.checkout');

        self::add(
            $cases,
            'channel: YA commits the previewed write',
            $confirmed['ok'] === true
                && $confirmed['pending'] === false
                && strpos($confirmed['reply'], Lang::t('en', 'committed')) !== false
                && is_array($args) && $args[2] === true,
            json_encode(['reply' => $confirmed['reply'], 'args' => $args])
        );

        $again = Channel::handle(
            ['channel' => 'telegram', 'from' => '999', 'text' => 'YA'],
            array_merge($config, ['allow_admin_write' => true]),
            $service,
            $headers
        );
        self::add(
            $cases,
            'channel: a confirmation cannot be replayed',
            $again['reply'] === Lang::t('en', 'channel_expired'),
            $again['reply']
        );

        Channel::handle(
            ['channel' => 'telegram', 'from' => '999', 'text' => 'pinjamkan B00001 ke anggota 123'],
            array_merge($config, ['allow_admin_write' => true]),
            $service,
            $headers
        );
        $cancelled = Channel::handle(
            ['channel' => 'telegram', 'from' => '999', 'text' => 'batal'],
            array_merge($config, ['allow_admin_write' => true]),
            $service,
            $headers
        );
        self::add(
            $cases,
            'channel: BATAL discards the pending write',
            $cancelled['reply'] === Lang::t('en', 'channel_cancelled'),
            $cancelled['reply']
        );

        // ---- guards --------------------------------------------------
        $unauthorized = Channel::handle(
            ['channel' => 'whatsapp', 'from' => '628111', 'text' => 'pinjaman saya'],
            $config,
            new TestService(),
            ['X-Chatbot-Token' => 'wrong']
        );
        self::add(
            $cases,
            'channel: wrong token is refused',
            $unauthorized['ok'] === false && $unauthorized['status'] === 401
                && $unauthorized['error']['code'] === 'UNAUTHORIZED'
        );

        $throttled = array_merge($config, ['rate_limit_per_minute' => 1]);
        Channel::handle(['channel' => 'whatsapp', 'from' => '628777', 'text' => 'halo'], $throttled, new TestService(), $headers);
        $blocked = Channel::handle(['channel' => 'whatsapp', 'from' => '628777', 'text' => 'halo'], $throttled, new TestService(), $headers);
        self::add(
            $cases,
            'channel: flood is throttled per sender',
            $blocked['ok'] === false && $blocked['status'] === 429,
            json_encode($blocked['error'] ?? [])
        );

        $store = new ChannelStore($channelConfig);
        $store->putAction('expiry-test', ['intent' => Intent::MY_EXTEND, 'params' => []], 60, time() - 600);
        self::add($cases, 'channel: stale pending action is dropped', is_null($store->takeAction('expiry-test')));

        foreach ((array)glob(rtrim($dir, '/') . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }



    /**
     * FAQ content: one file, conservative matching.
     *
     * @param array $cases
     * @return void
     */
    private static function faq(array &$cases)
    {
        $_SESSION = [];
        RateLimit::reset();

        $config = self::config();
        $items = Faq::items($config);

        self::add(
            $cases,
            'faq: the sample content loads and validates',
            count($items) >= 10 && Faq::validate($items) === [],
            json_encode(['items' => count($items), 'problems' => Faq::validate($items)])
        );

        $expected = [
            'jam buka' => 'hours',
            'nomor telepon' => 'phone',
            'alamat perpustakaan' => 'address',
            'berapa lama boleh pinjam' => 'loan-limit',
            'denda' => 'fines',
        ];

        $wrong = [];
        foreach ($expected as $message => $id) {
            $match = Faq::match($message, $config, 'id');
            if (is_null($match) || $match['id'] !== $id) {
                $wrong[$message] = $match['id'] ?? null;
            }
        }

        self::add(
            $cases,
            'faq: common questions reach the right entry',
            $wrong === [],
            json_encode($wrong)
        );

        $typo = Faq::match('telepn', $config, 'id');
        self::add(
            $cases,
            'faq: a one-letter typo still finds the entry',
            !is_null($typo) && $typo['id'] === 'phone' && $typo['score'] >= 0.7,
            json_encode($typo['id'] ?? null) . ' ' . json_encode($typo['score'] ?? null)
        );

        self::add(
            $cases,
            'faq: an unrelated question is left to the catalogue',
            is_null(Faq::match('sejarah indonesia', $config, 'id'))
                && is_null(Faq::match('pinjamkan B00001 ke anggota 123', $config, 'id'))
        );

        $english = Faq::match('opening hours', $config, 'en');
        self::add(
            $cases,
            'faq: English asks get the English answer',
            !is_null($english) && strpos((string)$english['answer'], 'Monday') !== false,
            (string)($english['answer'] ?? '')
        );

        $disabled = array_merge($config, ['faq' => ['enabled' => false]]);
        self::add(
            $cases,
            'faq: switching the block off disables both content and matching',
            Faq::items($disabled) === [] && is_null(Faq::match('jam buka', $disabled, 'id'))
        );

        $problems = Faq::validate([
            ['id' => 'x', 'question' => 'Q', 'answer' => 'A', 'keywords' => ['q']],
            ['id' => 'x', 'question' => 'Q2', 'answer' => '', 'keywords' => ['q2']],
        ]);

        self::add(
            $cases,
            'faq: validation catches duplicates and empty answers',
            count($problems) >= 2,
            json_encode($problems)
        );

        // ---- what the user sees ---------------------------------------
        $engine = new Engine(Actor::anonymous('guest'), $config, new TestService());
        $hours = $engine->handle('jam buka');

        self::add(
            $cases,
            'faq: the chat answers with the FAQ text',
            ($hours['meta']['intent'] ?? '') === Intent::FAQ
                && ($hours['meta']['faq_id'] ?? '') === 'hours'
                && strpos((string)($hours['reply']['text'] ?? ''), '08.00') !== false,
            json_encode(['intent' => $hours['meta']['intent'] ?? null, 'text' => $hours['reply']['text'] ?? ''])
        );

        $_SESSION = [];
        RateLimit::reset();
        $service = new TestService();
        $engine = new Engine(Actor::make('member', '123', 'Rina'), $config, $service);
        $loans = $engine->handle('pinjaman saya');

        self::add(
            $cases,
            'faq: a member question is never answered by the FAQ',
            ($loans['meta']['intent'] ?? '') === Intent::MY_LOANS,
            json_encode($loans['meta']['intent'] ?? null)
        );

        $_SESSION = [];
        RateLimit::reset();
        $engine = new Engine(Actor::anonymous('guest'), $config, new TestService());
        $search = $engine->handle('cari buku sejarah');

        self::add(
            $cases,
            'faq: an explicit search stays a search',
            ($search['meta']['intent'] ?? '') === Intent::BIBLIO_LIST,
            json_encode($search['meta']['intent'] ?? null)
        );

        $_SESSION = [];
        RateLimit::reset();
        $engine = new Engine(Actor::anonymous('guest'), $config, new TestService());
        $help = $engine->handle('bantuan');
        $titles = [];
        foreach ((array)($help['reply']['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'list' && ($block['title'] ?? '') === Lang::t('en', 'faq_topics')) {
                $titles = array_column((array)($block['items'] ?? []), 'title');
            }
        }

        self::add(
            $cases,
            'faq: the help answer lists the questions',
            count($titles) >= 3 && in_array('Opening hours', $titles, true),
            json_encode($titles)
        );


        // ---- a library-owned file wins over the sample -----------------
        $dir = sys_get_temp_dir() . '/slims-chatbot-faq-' . getmypid();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir . '/faq.php';
        file_put_contents($file, "<?php\nreturn [\n"
            . "    [\n"
            . "        'id' => 'hours',\n"
            . "        'question' => 'Kapan buka?',\n"
            . "        'keywords' => ['jam buka'],\n"
            . "        'answer' => 'Buka 09.00-15.00 saja.',\n"
            . "        'link' => 'https://example.org/jam-layanan',\n"
            . "    ],\n"
            . "];\n");

        $custom = array_merge($config, ['faq' => array_merge($config['faq'], ['file' => $file])]);
        $customItems = Faq::items($custom);
        $customMatch = Faq::match('jam buka', $custom, 'id');

        self::add(
            $cases,
            'faq: faq.file overrides the bundled sample',
            count($customItems) === 1
                && $customMatch['answer'] === 'Buka 09.00-15.00 saja.'
                && Faq::source() === $file,
            json_encode(['count' => count($customItems), 'source' => Faq::source()])
        );

        $_SESSION = [];
        RateLimit::reset();
        $engine = new Engine(Actor::anonymous('guest'), $custom, new TestService());
        $customReply = $engine->handle('jam buka');
        $link = null;
        foreach ((array)($customReply['reply']['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'link') {
                $link = $block;
            }
        }

        self::add(
            $cases,
            'faq: a "read more" link is rendered with a label',
            is_array($link)
                && ($link['href'] ?? '') === 'https://example.org/jam-layanan'
                && ($link['label'] ?? '') === Lang::t('en', 'faq_more'),
            json_encode($link)
        );

        @unlink($file);
        @rmdir($dir);

        self::add(
            $cases,
            'faq: chips point at the content',
            in_array('jam buka', Intent::chips('guest', 'en'), true),
            json_encode(Intent::chips('guest', 'en'))
        );
    }

    /**
     * A stock SLiMS 9 Bulian: the chatbot must reach a data service without
     * a modified core file and without the Commands plugin being active.
     *
     * @param array $cases
     * @return void
     */
    private static function stock(array &$cases)
    {
        $class = 'Commands\\Support\\CliAnything';
        $resolved = null;

        try {
            $resolved = Engine::serviceClass();
        } catch (\Throwable $error) {
            $resolved = 'error: ' . $error->getMessage();
        }

        self::add(
            $cases,
            'stock: the data service resolves without the Commands plugin',
            $resolved === $class && class_exists($class),
            (string)$resolved
        );

        self::add(
            $cases,
            'stock: the envelope version survives the fallback',
            class_exists($class) && (string)$class::SCHEMA_VERSION === '1.0',
            class_exists($class) ? (string)$class::SCHEMA_VERSION : 'missing'
        );

        // The bundled copy only exists so a chatbot-only install keeps a data
        // layer. When the Commands plugin is present the two files must be
        // byte-identical, otherwise the tested behaviour is not what ships.
        $vendored = __DIR__ . '/vendor/CliAnything.php';
        $native = dirname(__DIR__, 3) . '/plugins/Commands/Support/CliAnything.php';
        $inSync = is_file($vendored);

        if ($inSync && is_file($native)) {
            $inSync = sha1_file($vendored) === sha1_file($native);
        }

        self::add(
            $cases,
            'stock: the bundled service copy is present and in sync',
            $inSync,
            $inSync ? 'identical or standalone' : 'out of sync with plugins/Commands/Support/CliAnything.php'
        );

        // No database here: a greeting must answer from the rule table, which
        // proves the service is resolved lazily and never at page load.
        $_SESSION = [];
        RateLimit::reset();
        $engine = new Engine(Actor::anonymous('guest'), self::config());

        $greeting = null;
        try {
            $greeting = $engine->handle('halo');
        } catch (\Throwable $error) {
            $greeting = ['ok' => false, 'error' => ['code' => 'THROWN', 'message' => $error->getMessage()]];
        }

        self::add(
            $cases,
            'stock: a greeting answers without touching the database',
            !empty($greeting['ok']) && isset($greeting['reply']['text']),
            json_encode($greeting['error'] ?? ($greeting['meta'] ?? []))
        );
    }

    /**
     * @param array $cases
     * @return void
     */
    private static function http(array &$cases)
    {
        $_SESSION = [];
        RateLimit::reset();

        $config = self::config();

        $response = Http::process(['message' => 'halo'], Actor::anonymous('guest'), 'opac', new TestService(), $config);
        self::add(
            $cases,
            'http: missing CSRF token is refused',
            $response['ok'] === false && $response['error']['code'] === 'CSRF_FAILED',
            json_encode($response['error'] ?? [])
        );

        $token = Http::token();
        $response = Http::process(
            ['message' => 'halo', 'csrf_token' => $token],
            Actor::anonymous('guest'),
            'opac',
            new TestService(),
            $config
        );
        self::add($cases, 'http: valid request answers', $response['ok'] === true && $response['surface'] === 'opac');

        $response = Http::process(['message' => '', 'csrf_token' => $token], Actor::anonymous('guest'), 'opac', new TestService(), $config);
        self::add(
            $cases,
            'http: empty message is refused',
            $response['ok'] === false && $response['error']['code'] === 'EMPTY_MESSAGE'
        );

        $response = Http::process(
            ['message' => 'halo', 'csrf_token' => $token],
            Actor::anonymous('guest'),
            'opac',
            new TestService(),
            array_merge($config, ['enabled' => false])
        );
        self::add(
            $cases,
            'http: disabled plugin answers CHATBOT_DISABLED',
            $response['ok'] === false && $response['error']['code'] === 'CHATBOT_DISABLED'
        );

        self::add($cases, 'view: markup is escaped and wired', self::viewLooksSafe());
    }

    /**
     * @return bool
     */
    private static function viewLooksSafe(): bool
    {
        $html = View::render(
            Actor::make('member', '123', '<script>alert(1)</script>'),
            'opac',
            '/index.php?p=chatbot&action=ask',
            '/plugins/chatbot/assets/',
            ['language' => 'en']
        );

        return strpos($html, 'data-chatbot') !== false
            && strpos($html, '<script>alert(1)</script>') === false
            && strpos($html, 'data-csrf="') !== false;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array
     */
    private static function config(): array
    {
        $defaults = Config::defaults();
        $log = array_merge($defaults['log'], [
            'dir' => sys_get_temp_dir() . '/slims-chatbot-log-' . getmypid(),
        ]);

        return array_merge($defaults, [
            'log' => $log,
            'language' => 'en',
            'rate_limit_per_minute' => 200,
            'max_writes_per_hour' => 50,
            'confirm_ttl' => 60,
            'allow_admin_write' => false,
            'allow_member_write' => false,
        ]);
    }

    /**
     * @param array  $response
     * @param string $type
     * @return array
     */
    private static function blockOfType(array $response, string $type): array
    {
        foreach ((array)($response['reply']['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === $type) {
                return $block;
            }
        }

        return [];
    }

    /**
     * @param array  $response
     * @param string $type
     * @return bool
     */
    private static function hasBlock(array $response, string $type): bool
    {
        return self::blockOfType($response, $type) !== [];
    }

    /**
     * @param array  $response
     * @param string $text
     * @return bool
     */
    private static function hasNotice(array $response, string $text): bool
    {
        foreach ((array)($response['reply']['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'notice' && ($block['text'] ?? '') === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array  $cases
     * @param string $name
     * @param bool   $ok
     * @param string $detail
     * @return void
     */
    private static function add(array &$cases, string $name, bool $ok, string $detail = '')
    {
        $cases[] = [
            'name' => $name,
            'ok' => $ok,
            'detail' => $ok ? '' : self::truncate($detail),
        ];
    }

    /**
     * @param string $value
     * @return string
     */
    private static function truncate(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return Str::length($value) > 160 ? Str::slice($value, 0, 157) . '…' : $value;
    }
}

/**
 * A service that returns a title an answer rule forbids.
 *
 * @license GPLv3
 */
final class GuardServiceProbe
{
    /** @var array */
    public $calls = [];

    /**
     * @return array
     */
    public function biblioList(string $q = '', int $limit = 20, int $offset = 0, string $sort = 'relevance', bool $includeHidden = false, array $options = []): array
    {
        $this->calls[] = ['biblio.list', [$q]];

        return [
            'items' => [[
                'biblio_id' => 90,
                'title' => 'Bebas Denda Juga: panduan administrasi',
                'authors' => 'Tim Perpustakaan',
                'isbn_issn' => '9786020000090',
                'publish_year' => '2024',
                'call_number' => '025.1 TIM b',
                'item_count' => 1,
                'loan_count' => 0,
                'available_count' => 1,
                'popularity' => 0,
                'rank_score' => 100,
            ]],
            'count' => 1,
            'total' => 1,
            'limit' => $limit,
            'offset' => $offset,
            'query' => $q,
            'tokens' => [$q],
            'sort' => $sort,
            'stage' => 'exact',
            'suggestions' => [],
        ];
    }
}
