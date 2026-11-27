<?php
/**
 * Deterministic intent router
 *
 * No model, no network: a small ordered rule table maps what a librarian
 * actually types ("pinjaman terlambat", "cek item B00001", "cari buku
 * sejarah") to the same names the CLI uses (`loan.list`, `item.get`,
 * `biblio.list`). That keeps the chat honest: every answer has a command
 * behind it that a human can run and check.
 *
 * The class is pure: it never touches the database, the session or the
 * network, so it can be unit tested (`php index.php chatbot:selftest`).
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Intent
{
    const NONE = 'none';
    const GREETING = 'greeting';
    const THANKS = 'thanks';
    const HELP = 'help';
    const WHOAMI = 'whoami';

    const BIBLIO_LIST = 'biblio.list';
    const BIBLIO_GET = 'biblio.get';
    const ITEM_GET = 'item.get';
    const LOAN_LIST = 'loan.list';
    const MEMBER_GET = 'member.get';
    const REPORT_SUMMARY = 'report.summary';
    const DOCTOR = 'doctor';

    const LOAN_CHECKOUT = 'loan.checkout';
    const LOAN_CHECKIN = 'loan.checkin';
    const LOAN_EXTEND = 'loan.extend';

    const MY_LOANS = 'my.loans';
    const MY_PROFILE = 'my.profile';
    const MY_EXTEND = 'my.extend';

    /**
     * Resolve one message.
     *
     * @param string $message raw user text
     * @param string $role    guest|member|staff
     * @return array{name:string,params:array,confidence:float,matched:string}
     */
    public static function resolve(string $message, string $role = 'guest'): array
    {
        $raw = trim($message);
        $text = self::normalise($raw);

        if ($text === '') {
            return self::result(self::NONE, [], 0.0, 'empty');
        }

        $memberId = self::memberId($raw, $text);
        $biblioId = self::biblioId($text);
        $loanId = self::loanId($text);
        $isbn = self::isbn($raw);

        // Item codes are extracted after the member id is blanked out so
        // that "pinjamkan B00001 ke anggota M001" yields one item, not two.
        $codes = self::itemCodes($memberId === null ? $raw : str_ireplace($memberId, ' ', $raw));

        $self = self::isSelfReference($text);
        $role = self::normaliseRole($role);

        // ---- small talk -------------------------------------------------
        if (preg_match('/^(halo|hallo|hai|hi|hey|hello|selamat (pagi|siang|sore|malam)|assalamualaikum|salam)\b/u', $text)) {
            return self::result(self::GREETING, [], 0.95, 'greeting');
        }

        if (preg_match('/^(terima kasih|makasih|thanks|thank you|thx|oke? (makasih|terima kasih))\b/u', $text)) {
            return self::result(self::THANKS, [], 0.95, 'thanks');
        }

        if (preg_match('/^(bantuan|help|menu|perintah|commands?|apa (saja )?yang bisa|could you help)\b/u', $text)
            || preg_match('/\b(apa yang bisa (kamu|anda) (lakukan|bantu)|what can you do)\b/u', $text)) {
            return self::result(self::HELP, [], 0.9, 'help');
        }

        if (preg_match('/^(siapa (saya|aku)|who am i|akun saya|my account|profil saya|my profile)\b/u', $text)) {
            return self::result(self::WHOAMI, [], 0.9, 'whoami');
        }

        // ---- self service (member) --------------------------------------
        // "perpanjang pinjaman saya" must be a renewal, not a loan list,
        // so the renewal verb is checked before the "pinjaman" keyword.
        if (preg_match('/\b(perpanjang|renew|extend|perpanjangan)\b/u', $text) && ($self || $role === 'member')) {
            return self::result(self::MY_EXTEND, [
                'loan_id' => $loanId,
                'item_code' => $codes[0] ?? null,
            ], 0.9, 'my-extend');
        }

        if (preg_match('/\b(pinjaman|pinjam|loan|loans|borrowing)\b/u', $text) && $self) {
            return self::result(self::MY_LOANS, [
                'status' => preg_match('/\b(terlambat|overdue|telat|late)\b/u', $text) ? 'overdue' : 'active',
                'member' => $memberId,
            ], 0.92, 'my-loans');
        }

        if (preg_match('/\b(denda|fines?|penalt(y|ies))\b/u', $text) && ($self || $role !== 'staff')) {
            return self::result(self::MY_PROFILE, ['member' => $memberId], 0.88, 'my-fines');
        }

        // ---- staff circulation ------------------------------------------
        if ($role === 'staff') {
            if (preg_match('/\b(pinjamkan|checkout|check out|check-out|kasih pinjam)\b/u', $text)) {
                return self::result(self::LOAN_CHECKOUT, [
                    'member' => $memberId,
                    'items' => $codes,
                ], 0.9, 'checkout');
            }

            if (preg_match('/\b(kembalikan|pengembalian|checkin|check in|check-in|return(ed)?)\b/u', $text)) {
                return self::result(self::LOAN_CHECKIN, [
                    'loan_id' => $loanId,
                    'item_code' => $codes[0] ?? null,
                ], 0.9, 'checkin');
            }

            if (preg_match('/\b(perpanjang|renew|extend|perpanjangan)\b/u', $text)) {
                return self::result(self::LOAN_EXTEND, [
                    'loan_id' => $loanId,
                    'item_code' => $codes[0] ?? null,
                ], 0.9, 'extend');
            }
        }

        // ---- staff reporting --------------------------------------------
        if (preg_match('/\b(ringkasan|summary|statistik|statistics|laporan|report|angka|figures)\b/u', $text)
            || preg_match('/\b(hari ini|today)\b/u', $text)) {
            return self::result(self::REPORT_SUMMARY, [], 0.88, 'report');
        }

        if (preg_match('/\b(doctor|diagnos\w*|health|kesehatan|status (sistem|system)|capabilit\w*)\b/u', $text)) {
            return self::result(self::DOCTOR, [], 0.9, 'doctor');
        }

        if (preg_match('/\b(terlambat|overdue|telat|late|jatuh tempo|due)\b/u', $text)) {
            if ($role === 'member') {
                return self::result(self::MY_LOANS, ['status' => 'overdue'], 0.8, 'my-overdue');
            }

            return self::result(self::LOAN_LIST, [
                'status' => 'overdue',
                'member' => $memberId,
            ], 0.85, 'overdue');
        }

        if (preg_match('/\b(daftar pinjaman|loan list|pinjaman aktif|active loans|sedang dipinjam|on loan)\b/u', $text)) {
            return self::result(self::LOAN_LIST, [
                'status' => 'active',
                'member' => $memberId,
            ], 0.85, 'loan-list');
        }

        // ---- item -------------------------------------------------------
        if (!empty($codes) && preg_match('/\b(cek|check|status|item|eksemplar|copy|copies|kode|code|tersedia|available|barcode)\b/u', $text)) {
            return self::result(self::ITEM_GET, ['code' => $codes[0]], 0.9, 'item');
        }

        // ---- member (staff) ---------------------------------------------
        if (!is_null($memberId) && preg_match('/\b(anggota|member|pemustaka|patron|member card)\b/u', $text)) {
            return self::result(self::MEMBER_GET, ['member' => $memberId], 0.88, 'member');
        }

        // ---- bibliography ----------------------------------------------
        if (!is_null($biblioId)) {
            return self::result(self::BIBLIO_GET, ['id' => $biblioId], 0.9, 'biblio-id');
        }

        if (!empty($codes) && count($codes) === 1 && self::looksLikeWholeMessage($text, $codes[0])) {
            return self::result(self::ITEM_GET, ['code' => $codes[0]], 0.7, 'bare-item-code');
        }

        // ---- curated lists: "buku populer", "yang terbaru" ----------------
        $sortHint = self::sortHint($text);
        if ($sortHint !== 'relevance'
            && preg_match('/\b(buku|bacaan|koleksi|judul|daftar|list|book|books|rekomendasi|recommendation)\b/u', $text)) {
            return self::result(
                self::BIBLIO_LIST,
                ['q' => self::searchQuery($text), 'sort' => $sortHint],
                0.8,
                'curated'
            );
        }

        // ---- search ------------------------------------------------------
        if (preg_match('/\b(cari|carikan|temukan|search|find|look for|ada (buku|judul)|apakah ada)\b/u', $text) || !is_null($isbn)) {
            $query = self::searchQuery($text, $isbn);

            return self::result(
                self::BIBLIO_LIST,
                ['q' => $query, 'sort' => self::sortHint($text)],
                $query === '' ? 0.5 : 0.85,
                'search'
            );
        }

        // A short message with no keyword at all is most likely a title or
        // an author. Falling back to a catalogue search beats a dead end.
        if (Str::length($text) <= 60) {
            return self::result(
                self::BIBLIO_LIST,
                ['q' => self::searchQuery($text), 'sort' => self::sortHint($text)],
                0.4,
                'fallback-search'
            );
        }

        return self::result(self::NONE, [], 0.0, 'unmatched');
    }

    /**
     * Example messages shown as chips, in the user's language.
     *
     * @param string $role
     * @param string $locale
     * @return array
     */
    public static function chips(string $role, string $locale): array
    {
        $key = 'chips_' . self::normaliseRole($role);

        return Lang::lines($locale, $key);
    }

    /**
     * @param string $role
     * @return string
     */
    public static function normaliseRole(string $role): string
    {
        $role = strtolower(trim($role));

        return in_array($role, ['guest', 'member', 'staff'], true) ? $role : 'guest';
    }

    /**
     * Which ordering did the user ask for?
     *
     * "Buku yang paling banyak dipinjam" must not be answered with the
     * newest record, so the hint travels with the intent.
     *
     * @param string $text
     * @return string relevance|popular|newest
     */
    public static function sortHint(string $text): string
    {
        if (preg_match('/\b(populer|terlaris|terpopuler|favorit|favourite|favorite|paling banyak|sering dipinjam|most borrowed|popular|bestseller|best seller)\b/u', $text)) {
            return 'popular';
        }

        if (preg_match('/\b(terbaru|baru|baru datang|terkini|newest|latest|recent|new arrivals?)\b/u', $text)) {
            return 'newest';
        }

        return 'relevance';
    }

    /**
     * Lowercase, punctuation-light version of the message.
     *
     * @param string $message
     * @return string
     */
    public static function normalise(string $message): string
    {
        $text = Str::lower(trim($message));
        $text = preg_replace('/[^\p{L}\p{N}#@._-]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string)$text);

        return trim((string)$text);
    }

    /**
     * @param string $name
     * @param array  $params
     * @param float  $confidence
     * @param string $matched
     * @return array
     */
    private static function result(string $name, array $params, float $confidence, string $matched): array
    {
        return [
            'name' => $name,
            'params' => $params,
            'confidence' => $confidence,
            'matched' => $matched,
        ];
    }

    /**
     * Does the message talk about the speaker's own account?
     *
     * @param string $text
     * @return bool
     */
    private static function isSelfReference(string $text): bool
    {
        return (bool)preg_match('/\b(saya|aku|ku|me|my|mine|sendiri|own)\b/u', $text);
    }

    /**
     * @param string $text
     * @return int|null
     */
    private static function biblioId(string $text)
    {
        if (preg_match('/#\s*(\d{1,7})\b/u', $text, $match)) {
            return (int)$match[1];
        }

        if (preg_match('/\b(?:biblio(?:grafi)?|judul|title|buku|record)\s+#?(\d{1,7})\b/u', $text, $match)) {
            return (int)$match[1];
        }

        return null;
    }

    /**
     * @param string $text
     * @return int|null
     */
    private static function loanId(string $text)
    {
        if (preg_match('/\b(?:pinjaman|loan|transaksi|trans(?:action)?)\s*#?\s*(\d{1,7})\b/u', $text, $match)) {
            return (int)$match[1];
        }

        return null;
    }

    /**
     * @param string $raw
     * @param string $text
     * @return string|null
     */
    private static function memberId(string $raw, string $text)
    {
        $stopWords = ['saya', 'aku', 'ini', 'itu', 'yang', 'dan', 'atau', 'my', 'me', 'this', 'that'];

        if (preg_match('/\b(?:anggota|member|pemustaka|patron|nim|nis)\s*#?\s*([A-Za-z0-9][A-Za-z0-9._-]{1,19})\b/u', $text, $match)) {
            $candidate = $match[1];
            if (!in_array(strtolower($candidate), $stopWords, true)) {
                return $candidate;
            }
        }

        // A bare number after "id" is ambiguous, so only accept it when the
        // word "anggota"/"member" is somewhere in the message.
        if (preg_match('/\b(?:anggota|member|pemustaka|patron)\b/u', $text)
            && preg_match('/\b(\d{2,20})\b/u', $text, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Item codes look like B00001, K-1023 or R3A12.
     *
     * @param string $raw
     * @return array
     */
    private static function itemCodes(string $raw): array
    {
        if (!preg_match_all('/\b([A-Z]{1,4}[0-9]{0,3}-?[0-9]{2,6}[A-Z]?)\b/', $raw, $matches)) {
            return [];
        }

        $codes = [];
        foreach ($matches[1] as $code) {
            // A 10+ digit block is an ISBN, not an item code.
            if (preg_match('/^\d{10,}$/', $code) || strlen($code) < 3) {
                continue;
            }
            $codes[strtoupper($code)] = true;
        }

        return array_keys($codes);
    }

    /**
     * @param string $raw
     * @return string|null
     */
    private static function isbn(string $raw)
    {
        if (preg_match('/\b(97[89][0-9-]{10,15}|[0-9]{9}[0-9Xx])\b/', $raw, $match)) {
            return str_replace(['-', ' '], '', $match[1]);
        }

        return null;
    }

    /**
     * @param string      $text
     * @param string|null $isbn
     * @return string
     */
    private static function searchQuery(string $text, $isbn = null): string
    {
        if (!is_null($isbn)) {
            return $isbn;
        }

        $query = preg_replace(
            '/\b(cari|carikan|temukan|search|find|look|for|buku|book|books|judul|title|titles|tentang|about|apakah|ada|tersedia|available|di|in|the|a|an|please|tolong|saya|ingin|want|mau|populer|terpopuler|terlaris|favorit|favorite|rekomendasi|recommended|popular|bestseller|terbaru|terkini|newest|latest)\b/u',
            ' ',
            $text
        );

        return trim(preg_replace('/\s+/u', ' ', (string)$query));
    }

    /**
     * Is the message basically just this item code?
     *
     * @param string $text
     * @param string $code
     * @return bool
     */
    private static function looksLikeWholeMessage(string $text, string $code): bool
    {
        $rest = trim(str_replace(strtolower($code), '', $text));

        return $rest === '';
    }
}
