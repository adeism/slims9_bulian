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
        self::channels($cases);
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
        return array_merge(Config::defaults(), [
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
