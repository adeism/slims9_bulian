<?php
/**
 * Standalone test runner for the chatbot plugin
 *
 * Runs the same suite as `php index.php chatbot:selftest`, but without
 * booting SLiMS (no database, no session handler, no admin template):
 *
 *   php plugins/chatbot/tests/run.php
 *
 * Useful in CI, on a laptop, or inside a container that has PHP but no
 * working SLiMS configuration yet.
 *
 * @license GPLv3
 */

// CLI only. CHATBOT_TESTS lets an embeddable PHP build (php-wasm in CI,
// for example) run the same file without exposing it over HTTP.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !defined('CHATBOT_TESTS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('CLI only');
}

defined('INDEX_AUTH') or define('INDEX_AUTH', 1);
defined('SB') or define('SB', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);

require_once dirname(__DIR__) . '/bootstrap.php';

$report = \Chatbot\SelfTest::run();

foreach ($report['cases'] as $case) {
    $line = $case['ok'] ? 'PASS' : 'FAIL';

    echo $line . '  ' . $case['name'];

    if (!$case['ok'] && $case['detail'] !== '') {
        echo '  <- ' . $case['detail'];
    }

    echo PHP_EOL;
}

echo PHP_EOL;
echo sprintf('%d passed, %d failed', $report['passed'], $report['failed']), PHP_EOL;

exit($report['failed'] === 0 ? 0 : 1);
