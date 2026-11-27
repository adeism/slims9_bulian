<?php
/**
 * Read side of the conversation log
 *
 * Everything the staff page and `chatbot:log` need to turn log rows into
 * something a librarian can read: filters from the query string, paging,
 * percentages, CSV, and escaped HTML. Pure functions over `LogStore`, so the
 * whole thing is testable without a request.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class History
{
    /** Rows per page. */
    const PER_PAGE = 50;

    /** Guard on CSV size: a staff export is for review, not for analytics. */
    const CSV_LIMIT = 5000;

    /**
     * Turn `$_GET` into LogStore filters. Unknown or malformed values are
     * ignored rather than rejected: a broken bookmark should still show the
     * default week.
     *
     * @param array    $query
     * @param int|null $now
     * @return array
     */
    public static function filters(array $query, ?int $now = null): array
    {
        $now = is_null($now) ? time() : $now;
        $days = isset($query['days']) ? (int)$query['days'] : 7;
        $days = ($days >= 1 && $days <= 365) ? $days : 7;

        $filters = [
            'since' => $now - $days * 86400,
            'days' => $days,
            'page' => max(1, (int)($query['page'] ?? 1)),
            'per_page' => self::PER_PAGE,
        ];

        foreach (['surface', 'role', 'intent', 'faq_id'] as $key) {
            $value = isset($query[$key]) ? trim((string)$query[$key]) : '';

            if ($value !== '') {
                $filters[$key] = substr($value, 0, 40);
            }
        }

        if (isset($query['answered']) && $query['answered'] !== '') {
            $filters['answered'] = (string)$query['answered'] === '1';
        }

        if (isset($query['feedback']) && in_array($query['feedback'], ['up', 'down'], true)) {
            $filters['feedback'] = (string)$query['feedback'];
        }

        $text = isset($query['q']) ? trim((string)$query['q']) : '';

        if ($text !== '') {
            $filters['q'] = Str::slice($text, 0, 60);
        }

        return $filters;
    }

    /**
     * One page of rows plus the numbers the paginator needs.
     *
     * @param LogStore $log
     * @param array    $filters
     * @return array{rows:array,total:int,page:int,pages:int,per_page:int}
     */
    public static function page(LogStore $log, array $filters): array
    {
        $perPage = max(1, (int)($filters['per_page'] ?? self::PER_PAGE));
        $total = count($log->records($filters));
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, max(1, (int)($filters['page'] ?? 1)));

        $rows = $log->records(array_merge($filters, [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]));

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param LogStore $log
     * @param array    $filters
     * @return array
     */
    public static function summary(LogStore $log, array $filters): array
    {
        return $log->summary($filters);
    }

    /**
     * The columns of a CSV export. No member id, no name: the log does not
     * hold them in the first place.
     *
     * @param array $rows
     * @return string
     */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if (!is_resource($handle)) {
            return '';
        }

        fputcsv($handle, ['time', 'surface', 'role', 'intent', 'faq_id', 'guard', 'feedback', 'ms', 'question']);
        $written = 0;

        foreach ($rows as $row) {
            if ($written >= self::CSV_LIMIT) {
                break;
            }

            fputcsv($handle, [
                (string)($row['ts'] ?? ''),
                (string)($row['surface'] ?? ''),
                (string)($row['role'] ?? ''),
                (string)($row['intent'] ?? ''),
                (string)($row['faq_id'] ?? ''),
                (string)($row['guard'] ?? ''),
                (string)($row['feedback'] ?? ''),
                (string)($row['ms'] ?? ''),
                (string)($row['q'] ?? ''),
            ]);

            $written++;
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * The list as an escaped table. `q`, `intent` and friends come from the
     * database or from a stranger's message, so every cell is escaped here,
     * not in the template.
     *
     * @param array  $rows
     * @param string $locale
     * @param string $baseUrl current page without `req`, for the detail link
     * @return string
     */
    public static function table(array $rows, string $locale, string $baseUrl = ''): string
    {
        $out = [];
        $out[] = '<table class="chatbot-history"><thead><tr>';
        $out[] = '<th>' . self::e(Lang::t($locale, 'history_request')) . '</th>';
        $out[] = '<th>' . self::e(Lang::t($locale, 'history_question')) . '</th>';
        $out[] = '<th>' . self::e(Lang::t($locale, 'history_intent')) . '</th>';
        $out[] = '<th>' . self::e(Lang::t($locale, 'history_feedback')) . '</th>';
        $out[] = '<th>' . self::e(Lang::t($locale, 'history_ms')) . '</th>';
        $out[] = '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $req = (string)($row['req'] ?? '');
            $href = self::withQuery($baseUrl, 'req', $req);
            $out[] = '<tr>';
            $out[] = '<td><a href="' . self::e($href) . '">' . self::e(substr($req, 0, 8)) . '</a><br><small>'
                . self::e((string)($row['ts'] ?? '')) . '</small></td>';
            $out[] = '<td>' . self::e((string)($row['q'] ?? '')) . '</td>';
            $out[] = '<td>' . self::e((string)($row['intent'] ?? 'none'))
                . '<br><small>' . self::e((string)($row['surface'] ?? '') . ' · ' . (string)($row['role'] ?? '')) . '</small></td>';
            $out[] = '<td>' . self::e(self::feedbackLabel((string)($row['feedback'] ?? ''), $locale)) . '</td>';
            $out[] = '<td>' . (int)($row['ms'] ?? 0) . '</td>';
            $out[] = '</tr>';
        }

        $out[] = '</tbody></table>';

        return implode('', $out);
    }

    /**
     * The current page URL with one query value replaced, for pagination and
     * detail links that keep the active filters.
     *
     * @param string $baseUrl
     * @param string $key
     * @param string $value
     * @return string
     */
    public static function withQuery(string $baseUrl, string $key, string $value): string
    {
        $path = $baseUrl;
        $query = [];

        $position = strpos($baseUrl, '?');

        if ($position !== false) {
            $path = substr($baseUrl, 0, $position);
            parse_str(substr($baseUrl, $position + 1), $query);
        }

        $query[$key] = $value;
        $encoded = http_build_query($query);

        return $path . ($encoded !== '' ? '?' . $encoded : '');
    }

    /**
     * Drop the parameters that must not survive a filter change.
     *
     * @param string $uri
     * @return string
     */
    public static function baseUrl(string $uri): string
    {
        $path = (string)parse_url($uri, PHP_URL_PATH);
        $query = [];
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);

        foreach (['req', 'page', 'export'] as $drop) {
            unset($query[$drop]);
        }

        $encoded = http_build_query($query);

        return ($path === '' ? 'index.php' : $path) . ($encoded !== '' ? '?' . $encoded : '');
    }

    /**
     * @param array $actor
     * @param array $config
     * @return bool
     */
    public static function allowed(array $actor, array $config = []): bool
    {
        $role = Intent::normaliseRole((string)($actor['role'] ?? 'guest'));

        if ($role !== Policy::STAFF) {
            return false;
        }

        $config = $config === [] ? Config::all() : $config;

        return !empty($config['enabled'])
            && !empty($config['admin'])
            && !empty($config['admin_history'])
            && !empty($config['log']['enabled']);
    }

    /**
     * @param string $value
     * @param string $locale
     * @return string
     */
    private static function feedbackLabel(string $value, string $locale): string
    {
        if ($value === 'up') {
            return '+ ' . Lang::t($locale, 'ui_feedback_up');
        }

        if ($value === 'down') {
            return '- ' . Lang::t($locale, 'ui_feedback_down');
        }

        return '';
    }

    /**
     * @param string $value
     * @return string
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
