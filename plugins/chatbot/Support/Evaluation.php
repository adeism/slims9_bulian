<?php
/**
 * Measurement
 *
 * The offline suite checks that known answers stay correct; this class
 * measures how often the chat is right on a corpus of real phrasing, sweeps
 * the FAQ threshold to show whether the default sits on a cliff, fuzzes one
 * edit into every keyword, and checks the two languages have the same keys.
 *
 * Everything here is deterministic and database-free, so it runs in CI and
 * in `chatbot:eval`.
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Evaluation
{
    /** Accuracy over the corpus. */
    const MIN_ACCURACY = 0.95;

    /** Of the questions that should be answered, how many are. */
    const MIN_RECALL = 0.90;

    /** Of the answers given, how many are right (negatives included). */
    const MIN_PRECISION = 0.95;

    /** Default FAQ threshold; the sweep checks it is not on a cliff. */
    const DEFAULT_THRESHOLD = 0.62;

    /**
     * @return array
     */
    public static function corpus(): array
    {
        $rows = require __DIR__ . '/../tests/corpus.php';
        $out = [];

        foreach ((array)$rows as $row) {
            if (!is_array($row) || !isset($row[0], $row[1], $row[2])) {
                continue;
            }

            $out[] = [
                'q' => (string)$row[0],
                'role' => Intent::normaliseRole((string)$row[1]),
                'expect' => (string)$row[2],
                'category' => (string)($row[3] ?? 'general'),
            ];
        }

        return $out;
    }

    /**
     * What the engine would do with one message, minus the service call.
     *
     * @param string $message
     * @param string $role
     * @param array  $config
     * @return array{intent:string,confidence:float,faq:?string}
     */
    public static function classify(string $message, string $role, array $config = []): array
    {
        $intent = Intent::resolve($message, $role);
        $faq = null;

        if (in_array($intent['name'], [Intent::BIBLIO_LIST, Intent::NONE], true)
            && (float)($intent['confidence'] ?? 1.0) < 0.8) {
            $match = Faq::match($message, $config, Lang::locale($config));
            $faq = is_null($match) ? null : (string)$match['id'];
        }

        return [
            'intent' => (string)$intent['name'],
            'confidence' => (float)($intent['confidence'] ?? 0),
            'faq' => $faq,
        ];
    }

    /**
     * @param array $config
     * @return array{total:int,passed:int,failed:int,accuracy:float,by_category:array,failures:array}
     */
    public static function run(array $config = []): array
    {
        $rows = [];
        $categories = [];
        $failures = [];

        foreach (self::corpus() as $case) {
            $result = self::classify($case['q'], $case['role'], $config);
            $ok = self::accepts($case['expect'], $result);
            $rows[] = ['case' => $case, 'result' => $result, 'ok' => $ok];

            $category = $case['category'];
            $categories[$category]['total'] = ($categories[$category]['total'] ?? 0) + 1;
            $categories[$category]['passed'] = ($categories[$category]['passed'] ?? 0) + ($ok ? 1 : 0);

            if (!$ok && count($failures) < 25) {
                $failures[] = [
                    'q' => $case['q'],
                    'role' => $case['role'],
                    'expect' => $case['expect'],
                    'got' => $result['faq'] !== null ? 'faq:' . $result['faq'] : 'intent:' . $result['intent'],
                    'confidence' => $result['confidence'],
                ];
            }
        }

        $total = count($rows);
        $passed = count(array_filter($rows, function ($row) {
            return $row['ok'];
        }));

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
            'accuracy' => $total > 0 ? round($passed / $total, 4) : 0.0,
            'by_category' => $categories,
            'failures' => $failures,
        ];
    }

    /**
     * Precision and recall for every threshold in the range.
     *
     * @param array $config
     * @param float $from
     * @param float $to
     * @param float $step
     * @return array
     */
    public static function sweep(array $config = [], float $from = 0.40, float $to = 0.80, float $step = 0.02): array
    {
        $corpus = self::corpus();
        $out = [];

        for ($threshold = $from; $threshold <= $to + 0.0001; $threshold += $step) {
            $candidate = $config;
            $candidate['faq'] = array_merge((array)($config['faq'] ?? []), ['threshold' => round($threshold, 2)]);
            $tp = 0;
            $fp = 0;
            $fn = 0;
            $positives = 0;

            foreach ($corpus as $case) {
                $expected = strpos($case['expect'], 'faq:') === 0;
                $wanted = $expected ? substr($case['expect'], 4) : null;
                $result = self::classify($case['q'], $case['role'], $candidate);
                $faq = $result['faq'];

                if ($expected) {
                    $positives++;
                }

                if (!is_null($faq) && $expected && $faq === $wanted) {
                    $tp++;
                } elseif (!is_null($faq)) {
                    $fp++;
                } elseif ($expected) {
                    $fn++;
                }
            }

            $out[number_format($threshold, 2)] = [
                'precision' => $tp + $fp > 0 ? round($tp / ($tp + $fp), 3) : 1.0,
                'recall' => $positives > 0 ? round($tp / $positives, 3) : 1.0,
                'answers' => $tp + $fp,
            ];
        }

        return $out;
    }

    /**
     * One edit into every keyword: delete a character, swap two neighbours,
     * double a character. A chat that only answers perfect spelling is not
     * tolerant, whatever the test for one typo says.
     *
     * @param array $config
     * @param int   $perKeyword
     * @return array{ratio:float,checked:int,missed:array}
     */
    public static function typoRobustness(array $config = [], int $perKeyword = 4): array
    {
        $checked = 0;
        $matched = 0;
        $missed = [];

        foreach (Faq::items($config) as $item) {
            $id = (string)($item['id'] ?? '');
            $keywords = array_slice((array)($item['keywords'] ?? []), 0, 3);

            foreach ($keywords as $keyword) {
                // Measuring one-edit tolerance on two- and three-letter words
                // says more about the language than about the matcher.
                if (Str::length((string)$keyword) < 5) {
                    continue;
                }

                foreach (array_slice(self::variants((string)$keyword), 0, $perKeyword) as $variant) {
                    $checked++;
                    $match = Faq::match($variant, $config, Lang::locale($config));

                    if (!is_null($match) && (string)$match['id'] === $id) {
                        $matched++;
                    } elseif (count($missed) < 20) {
                        $missed[] = [$variant, 'expected ' . $id, 'got ' . ($match['id'] ?? 'none')];
                    }
                }
            }
        }

        return [
            'ratio' => $checked > 0 ? round($matched / $checked, 3) : 1.0,
            'checked' => $checked,
            'missed' => $missed,
        ];
    }

    /**
     * @param string $phrase
     * @return array
     */
    public static function variants(string $phrase): array
    {
        $length = Str::length($phrase);
        $out = [];

        if ($length < 4) {
            return $out;
        }

        for ($index = 1; $index < $length - 1 && count($out) < 12; $index++) {
            $without = Str::slice($phrase, 0, $index) . Str::slice($phrase, $index + 1);
            $out[] = $without;

            $swapped = Str::slice($phrase, 0, $index)
                . Str::slice($phrase, $index + 1, 1)
                . Str::slice($phrase, $index, 1)
                . Str::slice($phrase, $index + 2);
            $out[] = $swapped;

            $out[] = Str::slice($phrase, 0, $index + 1) . Str::slice($phrase, $index, 1) . Str::slice($phrase, $index + 1);
        }

        return array_values(array_unique($out));
    }

    /**
     * The two language files must agree: same keys, same placeholders.
     *
     * @return array problems
     */
    public static function langParity(): array
    {
        $en = Lang::messages('en');
        $id = Lang::messages('id');
        $problems = [];

        foreach (array_diff(array_keys($en), array_keys($id)) as $key) {
            $problems[] = 'missing in id: ' . $key;
        }

        foreach (array_diff(array_keys($id), array_keys($en)) as $key) {
            $problems[] = 'missing in en: ' . $key;
        }

        foreach ($en as $key => $value) {
            if (!array_key_exists($key, $id)) {
                continue;
            }

            if (is_array($value) || is_array($id[$key])) {
                if ((array)$value !== [] && (array)$id[$key] === []) {
                    $problems[] = 'empty list in id: ' . $key;
                }

                continue;
            }

            $left = self::placeholders((string)$value);
            $right = self::placeholders((string)$id[$key]);

            if ($left !== $right) {
                $problems[] = 'placeholders differ for ' . $key . ': ' . implode(',', $left) . ' vs ' . implode(',', $right);
            }

            if (trim((string)$value) === '' || trim((string)$id[$key]) === '') {
                $problems[] = 'empty string: ' . $key;
            }
        }

        return $problems;
    }

    /**
     * Every answer the browser can paint must be well formed: text, block
     * shapes, no unresolved placeholder, no markup, and a login link where
     * one is promised.
     *
     * @param array $config
     * @return array problems
     */
    public static function renderContract(array $config = []): array
    {
        $problems = [];
        $keys = array_keys(Lang::messages('en'));
        $scenarios = [
            ['halo', 'guest'],
            ['bantuan', 'guest'],
            ['jam buka', 'guest'],
            ['cari buku sejarah', 'guest'],
            ['zzz', 'guest'],
            ['pinjaman saya', 'guest'],
            ['pinjaman saya', 'member'],
            ['denda saya', 'member'],
            ['pinjaman terlambat', 'staff'],
            ['ringkasan hari ini', 'staff'],
            ['pinjaman saya jam buka', 'member'],
        ];

        foreach ($scenarios as $scenario) {
            $_SESSION = [];
            RateLimit::reset();
            if ($scenario[1] === 'staff') {
                $actor = ['role' => 'staff', 'id' => '1', 'username' => 'rina'];
            } elseif ($scenario[1] === 'member') {
                $actor = ['role' => 'member', 'member_id' => '123', 'name' => 'Rina'];
            } else {
                $actor = Actor::anonymous('guest');
            }
            $engine = new Engine($actor, $config, new TestService());
            $response = $engine->handle($scenario[0]);
            $label = $scenario[0] . ' [' . $scenario[1] . ']';

            if (empty($response['ok'])) {
                $problems[] = $label . ': ok is false';
            }

            if (trim((string)($response['reply']['text'] ?? '')) === '') {
                $problems[] = $label . ': empty reply text';
            }

            foreach ((array)($response['reply']['blocks'] ?? []) as $block) {
                if (!isset($block['type']) || !is_string($block['type'])) {
                    $problems[] = $label . ': block without type';
                    continue;
                }

                if ($block['type'] === 'link' && (empty($block['label']) || empty($block['href']))) {
                    $problems[] = $label . ': link block without label or href';
                }
            }

            $visible = (string)($response['reply']['text'] ?? '')
                . ' ' . json_encode($response['reply']['blocks'] ?? [], JSON_UNESCAPED_UNICODE);

            if (strpos($visible, '<') !== false) {
                $problems[] = $label . ': markup in the payload';
            }

            foreach ($keys as $key) {
                if (strpos($visible, ':' . $key) !== false) {
                    $problems[] = $label . ': unresolved placeholder :' . $key;
                }
            }

            if (!is_array($response['suggestions'] ?? null) || $response['suggestions'] === []) {
                $problems[] = $label . ': no suggestions';
            }

            if (!array_key_exists('writes_enabled', (array)($response['meta'] ?? []))) {
                $problems[] = $label . ': meta.writes_enabled missing';
            }
        }

        return $problems;
    }

    /**
     * @param string $expect
     * @param array  $result
     * @return bool
     */
    public static function accepts(string $expect, array $result): bool
    {
        if ($expect === 'no-faq') {
            return is_null($result['faq']);
        }

        if (strpos($expect, 'faq:') === 0) {
            return $result['faq'] === substr($expect, 4);
        }

        if (strpos($expect, 'intent:') === 0) {
            return $result['intent'] === substr($expect, 7) && is_null($result['faq']);
        }

        return false;
    }

    /**
     * @param string $text
     * @return array
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/', $text, $matches);
        $found = array_values(array_unique((array)($matches[1] ?? [])));
        sort($found);

        return $found;
    }
}
