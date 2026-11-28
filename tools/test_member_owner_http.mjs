#!/usr/bin/env node
/** Isolated PHP HTTP-response smoke tests; not FPM, proxy or database tests.
 * Install @php-wasm/node and @php-wasm/universal outside the repository, then:
 * PHP_WASM_NODE_MODULES=/path/to/node_modules node tools/test_member_owner_http.mjs
 */
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const modules = process.env.PHP_WASM_NODE_MODULES;
if (!modules || !path.isAbsolute(modules)) {
    throw new Error('Set PHP_WASM_NODE_MODULES to an absolute external node_modules directory');
}
const require = createRequire(path.join(modules, '__member_owner_test__.cjs'));
const { PHP } = await import(require.resolve('@php-wasm/universal'));
const { loadNodeRuntime, createNodeFsMountHandler } = await import(require.resolve('@php-wasm/node'));
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/repo');
await php.mount('/repo', createNodeFsMountHandler(root));
let cases = 0;
function denied(response) {
    assert.equal(response.httpStatusCode, 503);
    assert.match(response.headers['content-type']?.join('') ?? '', /^text\/plain; charset=utf-8$/);
    assert.match(response.headers['cache-control']?.join('') ?? '', /no-store/);
    assert.deepEqual(response.headers['x-content-type-options'], ['nosniff']);
    assert.deepEqual(response.headers['referrer-policy'], ['no-referrer']);
    assert.equal(response.headers.location, undefined, 'Never redirect/replay a legacy POST');
    assert(!response.text.includes('UNSAFE'));
    assert(!response.text.includes('Fatal error'));
    cases++;
}
try {
    for (const route of ['member', 'download_current_loan', 'download_loan_history', 'download_member_loan_report']) {
        for (const method of ['GET', 'POST', 'HEAD']) {
            denied(await php.run({
                relativeUri: '/index.php?p=' + route,
                method,
                env: { SLIMS_MEMBER_OWNER: 'rust' },
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: 'legacy=old-token' },
                body: method === 'POST' ? new TextEncoder().encode('logMeIn=1&memberID=M001&memberPassWord=not-a-secret') : undefined,
                code: "<?php chdir('/repo'); require '/repo/index.php'; echo 'UNSAFE';",
            }));
        }
    }
    // Malformed owner fails closed too, before application/database bootstrap.
    denied(await php.run({ relativeUri: '/index.php?p=member', env: { SLIMS_MEMBER_OWNER: 'typo' },
        code: "<?php require '/repo/index.php'; echo 'UNSAFE';" }));
    // Request-supplied values cannot override the worker's configured owner.
    denied(await php.run({ relativeUri: '/index.php?p=member&SLIMS_MEMBER_OWNER=php', method: 'POST',
        env: { SLIMS_MEMBER_OWNER: 'rust' }, headers: { 'SLIMS_MEMBER_OWNER': 'php', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new TextEncoder().encode('SLIMS_MEMBER_OWNER=php'), code: "<?php require '/repo/index.php'; echo 'UNSAFE';" }));
    for (const owner of ['', 'php']) {
        const response = await php.run({ env: { SLIMS_MEMBER_OWNER: owner },
            code: "<?php require '/repo/lib/member_owner_guard.inc.php'; slims_require_php_member_owner(); echo 'ALLOWED';" });
        assert.equal(response.httpStatusCode, 200);
        assert.equal(response.text, 'ALLOWED');
        cases++;
    }
    console.log(`PASS: ${cases} isolated PHP HTTP-response cases (no FPM/proxy/database)`);
} finally {
    php.exit();
}
