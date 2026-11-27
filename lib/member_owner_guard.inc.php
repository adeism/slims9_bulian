<?php
/**
 * Opt-in cutover gate, not authorization or proof of exclusive DB writers.
 * Set SLIMS_MEMBER_OWNER=rust in EVERY PHP worker environment only after routing
 * member traffic to Rust. Default php preserves the legacy application.
 */
function slims_php_member_owner_allowed(): bool
{
    $owner = getenv('SLIMS_MEMBER_OWNER');
    return $owner === false || $owner === '' || $owner === 'php';
}

function slims_require_php_member_owner(): void
{
    if (slims_php_member_owner_allowed()) {
        return;
    }
    // Unknown values fail closed on guarded paths. Never redirect a legacy
    // POST: its payload and CSRF token are not compatible with Rust.
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    exit('PHP member operations are disabled. Use the configured member service.');
}
