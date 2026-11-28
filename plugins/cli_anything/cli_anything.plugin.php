<?php
/**
 * Plugin Name: CLI-Anything Bridge
 * Plugin URI: https://github.com/adeism/slims9_bulian
 * Description: HTTP bridge for CLI-Anything agent harnesses. Read endpoints need a shared token; write endpoints additionally need allow_write, POST and commit=1 (plus allow_bulk with an automatic database backup for multi-record calls).
 * Version: 1.0.0
 * Author: SLiMS Bulian contributors
 * Author URI: https://slims.web.id
 */

use SLiMS\Plugins;

$plugin = Plugins::getInstance();

// The bridge needs the CLI-Anything service classes shipped with this repo.
if (!class_exists(\Commands\Support\CliAnything::class)) {
    return;
}

// The bridge is inert unless explicitly enabled *and* a token is set.
$cliAnythingOptions = \Commands\Support\CliAnything::options();
if (empty($cliAnythingOptions['enabled']) || trim((string)$cliAnythingOptions['token']) === '') {
    return;
}

$plugin->register('custom_api_route', function ($router) {
    $bridge = function () {
        return new \Commands\Support\CliAnythingHttp();
    };

    /* ---------- read endpoints ---------- */

    $router->map('GET', '/cli-anything/v1/status', function () use ($bridge) {
        $bridge()->dispatch('doctor');
    });

    $router->map('GET', '/cli-anything/v1/biblio', function () use ($bridge) {
        $bridge()->dispatch('biblio.list');
    });

    $router->map('GET', '/cli-anything/v1/biblio/[*:id]', function ($id) use ($bridge) {
        $bridge()->dispatch('biblio.get', ['id' => $id]);
    });

    $router->map('GET', '/cli-anything/v1/item/[*:code]', function ($code) use ($bridge) {
        $bridge()->dispatch('item.get', ['code' => $code]);
    });

    $router->map('GET', '/cli-anything/v1/member/[*:id]', function ($id) use ($bridge) {
        $bridge()->dispatch('member.get', ['id' => $id]);
    });

    $router->map('GET', '/cli-anything/v1/loan', function () use ($bridge) {
        $bridge()->dispatch('loan.list');
    });

    $router->map('GET', '/cli-anything/v1/report/summary', function () use ($bridge) {
        $bridge()->dispatch('report.summary');
    });

    /* ---------- write endpoints (gated) ---------- */

    $router->map('POST', '/cli-anything/v1/loan/checkout', function () use ($bridge) {
        $bridge()->dispatch('loan.checkout');
    });

    $router->map('POST', '/cli-anything/v1/loan/checkin', function () use ($bridge) {
        $bridge()->dispatch('loan.checkin');
    });

    $router->map('POST', '/cli-anything/v1/loan/extend', function () use ($bridge) {
        $bridge()->dispatch('loan.extend');
    });
});
