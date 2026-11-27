<?php
/**
 * CLI-Anything for SLiMS 9 Bulian — HTTP bridge controller
 *
 * Exposed by `plugins/cli_anything/cli_anything.plugin.php` through the
 * `custom_api_route` hook, so it is reachable at
 *
 *   <slims>/index.php?p=api/cli-anything/v1/<endpoint>
 *
 * Authentication is a single shared token (`config/cli_anything.php`
 * or `SLIMS_CLI_ANYTHING_TOKEN`). Reads only need the token; writes
 * additionally need `allow_write` + `commit=1` (and `allow_bulk` for
 * multi-record calls).
 *
 * @license GPLv3
 */

namespace Commands\Support;

class CliAnythingHttp
{
    const SCHEMA_HEADER = 'X-CLI-Anything-Schema';

    /**
     * @var CliAnything
     */
    private $service;

    public function __construct()
    {
        // The service is created lazily inside dispatch() so that a broken
        // database connection still returns a JSON envelope instead of a
        // white page.
    }

    /**
     * Dispatch one endpoint and emit a JSON envelope.
     *
     * @param string $action e.g. biblio.list
     * @param array  $routeParams values captured by the router
     * @return void
     */
    public function dispatch(string $action, array $routeParams = [])
    {
        try {
            $this->service = CliAnything::instance();
            $this->guardToken();

            $params = array_merge($this->params(), $routeParams);
            $data = $this->run($action, $params);

            $this->respond(CliAnything::success($action, $data, ['transport' => 'http']), 200);
        } catch (CliAnythingException $error) {
            $this->respond($error->toArray(), $this->statusFor($error->errorCode()));
        } catch (\Throwable $error) {
            $this->respond([
                'ok' => false,
                'schema' => CliAnything::SCHEMA_VERSION,
                'error' => [
                    'code' => 'UNEXPECTED_ERROR',
                    'message' => $error->getMessage(),
                    'context' => [],
                ],
            ], 500);
        }
    }

    /**
     * @param string $action
     * @param array  $params
     * @return array
     */
    private function run(string $action, array $params): array
    {
        switch ($action) {
            case 'doctor':
                return $this->service->doctor();

            case 'biblio.list':
                return $this->service->biblioList(
                    $this->stringParam($params, 'q'),
                    $this->intParam($params, 'limit', 20),
                    $this->intParam($params, 'offset', 0),
                    $this->stringParam($params, 'sort', false, 'relevance')
                );

            case 'biblio.get':
                return $this->service->biblioGet($this->intParam($params, 'id', 0, true));

            case 'item.get':
                return $this->service->itemGet($this->stringParam($params, 'code', true));

            case 'member.get':
                $reveal = $this->truthy($params['reveal'] ?? false);
                if ($reveal && !(bool)$this->service->option('allow_pii_reveal', false)) {
                    throw new CliAnythingException(
                        'PII_REVEAL_DISABLED',
                        'Revealing patron PII over HTTP is disabled. Enable allow_pii_reveal in config/cli_anything.php.'
                    );
                }

                return $this->service->memberGet(
                    $this->stringParam($params, 'id', true),
                    $reveal ? false : (bool)$this->service->option('redact_pii', true)
                );

            case 'loan.list':
                return $this->service->loanList(
                    $this->stringParam($params, 'status', false, 'active'),
                    $this->stringParam($params, 'member'),
                    $this->intParam($params, 'limit', 20)
                );

            case 'report.summary':
                return $this->service->reportSummary();

            case 'loan.checkout':
                $this->requirePost('loan.checkout');
                $items = $this->itemsParam($params);

                return $this->service->loanCheckout(
                    $this->stringParam($params, 'member', true),
                    $items,
                    $this->truthy($params['commit'] ?? false)
                );

            case 'loan.checkin':
                $this->requirePost('loan.checkin');

                return $this->service->loanCheckin(
                    isset($params['loanid']) && $params['loanid'] !== '' ? (int)$params['loanid'] : null,
                    isset($params['itemcode']) && $params['itemcode'] !== '' ? (string)$params['itemcode'] : null,
                    $this->truthy($params['commit'] ?? false)
                );

            case 'loan.extend':
                $this->requirePost('loan.extend');

                return $this->service->loanExtend(
                    $this->intParam($params, 'loanid', 0, true),
                    $this->truthy($params['commit'] ?? false)
                );
        }

        throw new CliAnythingException('UNKNOWN_ENDPOINT', 'Unknown endpoint: ' . $action);
    }

    // -----------------------------------------------------------------
    // Request handling
    // -----------------------------------------------------------------

    private function guardToken()
    {
        $expected = trim((string)$this->service->option('token', ''));

        if ($expected === '') {
            throw new CliAnythingException(
                'BRIDGE_NOT_CONFIGURED',
                'No token configured. Set token in config/cli_anything.php or SLIMS_CLI_ANYTHING_TOKEN.'
            );
        }

        $provided = $this->providedToken();

        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new CliAnythingException('UNAUTHORIZED', 'Invalid or missing CLI-Anything token.');
        }
    }

    private function providedToken(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        foreach ($headers as $name => $value) {
            if (strcasecmp((string)$name, 'X-CLI-Anything-Token') === 0) {
                return trim((string)$value);
            }
            if (strcasecmp((string)$name, 'Authorization') === 0 && stripos((string)$value, 'Bearer ') === 0) {
                return trim(substr((string)$value, 7));
            }
        }

        if (isset($_SERVER['HTTP_X_CLI_ANYTHING_TOKEN'])) {
            return trim((string)$_SERVER['HTTP_X_CLI_ANYTHING_TOKEN']);
        }

        return '';
    }

    private function params(): array
    {
        $params = $_GET;
        unset($params['p']);

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $params = array_merge($params, $decoded);
            }
        } elseif (!empty($_POST)) {
            $params = array_merge($params, $_POST);
        }

        return $params;
    }

    private function requirePost(string $action)
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') {
            throw new CliAnythingException(
                'METHOD_NOT_ALLOWED',
                sprintf('%s is a write endpoint; use HTTP POST with commit=1.', $action)
            );
        }
    }

    private function itemsParam(array $params): array
    {
        if (isset($params['items'])) {
            $items = is_array($params['items']) ? $params['items'] : explode(',', (string)$params['items']);

            return array_values(array_filter(array_map('trim', $items)));
        }

        if (isset($params['item'])) {
            return [(string)$params['item']];
        }

        return [];
    }

    private function stringParam(array $params, string $key, bool $required = false, string $default = ''): string
    {
        $value = isset($params[$key]) && is_scalar($params[$key]) ? trim((string)$params[$key]) : '';

        if ($value === '') {
            if ($required) {
                throw new CliAnythingException('INVALID_ARGUMENT', sprintf('Parameter "%s" is required.', $key));
            }

            return $default;
        }

        return $value;
    }

    private function intParam(array $params, string $key, int $default = 0, bool $required = false): int
    {
        if (!isset($params[$key]) || $params[$key] === '') {
            if ($required) {
                throw new CliAnythingException('INVALID_ARGUMENT', sprintf('Parameter "%s" is required.', $key));
            }

            return $default;
        }

        if (!is_numeric($params[$key])) {
            throw new CliAnythingException('INVALID_ARGUMENT', sprintf('Parameter "%s" must be numeric.', $key));
        }

        return (int)$params[$key];
    }

    private function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var((string)$value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed === true;
    }

    private function statusFor(string $errorCode): int
    {
        $map = [
            'UNAUTHORIZED' => 401,
            'BRIDGE_NOT_CONFIGURED' => 503,
            'WRITE_DISABLED' => 403,
            'BULK_DISABLED' => 403,
            'PII_REVEAL_DISABLED' => 403,
            'METHOD_NOT_ALLOWED' => 405,
            'INVALID_ARGUMENT' => 400,
            'UNKNOWN_ENDPOINT' => 404,
            'BIBLIO_NOT_FOUND' => 404,
            'ITEM_NOT_FOUND' => 404,
            'MEMBER_NOT_FOUND' => 404,
            'LOAN_NOT_FOUND' => 404,
            'MEMBER_EXPIRED' => 409,
            'MEMBER_PENDING' => 409,
            'LOAN_REJECTED' => 409,
            'ITEM_RESERVED' => 409,
            'RENEW_LIMIT_REACHED' => 409,
            'TRANSACTION_FAILED' => 500,
            'BACKUP_FAILED' => 500,
            'QUERY_FAILED' => 500,
        ];

        return $map[$errorCode] ?? 400;
    }

    private function respond(array $payload, int $status)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header(self::SCHEMA_HEADER . ': ' . CliAnything::SCHEMA_VERSION);
            header('Cache-Control: no-store');
        }

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
