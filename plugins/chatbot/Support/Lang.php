<?php
/**
 * Chatbot copy (English + Indonesian)
 *
 * Strings are kept in PHP instead of gettext on purpose: the chat runs on
 * the OPAC and inside the staff area, and a librarian should be able to
 * fix a sentence by editing one file without rebuilding a .mo catalogue.
 *
 * `:placeholder` tokens are replaced by Lang::t().
 *
 * @license GPLv3
 */

namespace Chatbot;

final class Lang
{
    /**
     * Resolve the locale from configuration (or $sysconf when 'auto').
     *
     * @param array $config
     * @return string 'id' or 'en'
     */
    public static function locale(array $config = []): string
    {
        $setting = strtolower((string)($config['language'] ?? 'auto'));

        if (in_array($setting, ['id', 'en'], true)) {
            return $setting;
        }

        $default = '';

        if (isset($GLOBALS['sysconf']['default_lang'])) {
            $default = strtolower((string)$GLOBALS['sysconf']['default_lang']);
        } elseif (function_exists('config')) {
            $default = strtolower((string)config('default_lang', ''));
        }

        return strpos($default, 'id') === 0 ? 'id' : 'en';
    }

    /**
     * @param string $locale
     * @param string $key
     * @param array  $replace
     * @return string
     */
    public static function t(string $locale, string $key, array $replace = []): string
    {
        $value = self::messages($locale)[$key] ?? null;

        if (is_null($value)) {
            $value = self::messages('en')[$key] ?? $key;
        }

        if (!is_string($value)) {
            return '';
        }

        foreach ($replace as $name => $replacement) {
            $value = str_replace(':' . $name, (string)$replacement, $value);
        }

        return $value;
    }

    /**
     * A list value (chips, examples).
     *
     * @param string $locale
     * @param string $key
     * @return array
     */
    public static function lines(string $locale, string $key): array
    {
        $value = self::messages($locale)[$key] ?? self::messages('en')[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param string $locale
     * @return array
     */
    public static function messages(string $locale): array
    {
        $locale = $locale === 'id' ? 'id' : 'en';

        return $locale === 'id' ? self::indonesian() : self::english();
    }

    /**
     * @return array
     */
    private static function english(): array
    {
        return [
            // ---- chrome -------------------------------------------------
            'ui_title' => 'Library assistant',
            'ui_subtitle_guest' => 'Search the catalogue. Log in to see your own loans and fines.',
            'ui_subtitle_member' => 'Ask about the catalogue, or about your own loans and fines.',
            'ui_subtitle_staff' => 'Catalogue, circulation and daily figures. Transactions always ask for confirmation first.',
            'ui_placeholder' => 'Type a question, e.g. "pinjaman terlambat" or "cari buku sejarah"',
            'ui_send' => 'Send',
            'ui_thinking' => 'Looking it up…',
            'ui_role_guest' => 'Guest',
            'ui_role_member' => 'Member',
            'ui_role_staff' => 'Staff',
            'ui_login' => 'Log in to the member area',
            'ui_write_off' => 'Writes are disabled by the administrator: questions still work, transactions do not.',
            'ui_member_write_off' => 'Renewal from the chat is disabled by the administrator.',
            'ui_powered' => 'Same engine as the SLiMS CLI (CLI-Anything)',
            'ui_chips' => 'Try one of these:',
            'ui_network_error' => 'The connection failed. Please try again.',
            'ui_session_expired' => 'Your session expired. Reload the page and log in again.',
            'ui_confirm' => 'Confirm',
            'ui_cancel' => 'Cancel',
            'ui_cancelled' => 'Cancelled. Nothing was written.',
            'ui_disabled' => 'The library assistant is currently switched off.',
            'ui_hint_guest' => 'You are browsing as a guest. Loan details appear after you log in.',
            'ui_hint_member' => 'Answers are limited to your own account.',

            // ---- intents ------------------------------------------------
            'greet_guest' => 'Welcome. I can search the catalogue and check whether a title is available. Ask me "cari buku …" or "apakah buku #12 tersedia?"',
            'greet_member' => 'Welcome back, :name. I can look up your loans, your fines, and the catalogue.',
            'greet_staff' => 'Hello :name. I can search the catalogue, look at circulation and show today\'s figures. A transaction is only written after you confirm it.',
            'help' => 'I understand short questions about the collection and, depending on who you are, about circulation. I never delete records and I never show another patron\'s data.',
            'not_understood' => 'I did not catch that. :examples',
            'need_login' => 'Loan details belong to a member account. Please log in to the member area, then ask again.',
            'denied_guest' => 'That is staff or member information, so I cannot answer it here.',
            'denied_member' => 'That is staff information. Ask a librarian at the circulation desk.',
            'denied_role' => 'Your role is not allowed to do that.',
            'no_results' => 'Nothing matched ":query". Try a shorter title, an author name, or an ISBN.',
            'biblio_header' => 'Found :count of :total title(s) for ":query":',
            'biblio_header_empty' => 'Latest titles in the catalogue:',
            'biblio_header_popular' => 'Titles borrowed most often:',
            'search_did_you_mean' => 'No exact match for ":query". Did you mean one of these?',
            'search_approximate' => 'Nothing matched ":query" exactly, so here are the closest titles.',
            'search_retry' => 'Send the title again, use fewer words, or name the author.',
            'search_tips' => 'Typos are tolerated: a short title, an author name, or an ISBN all work.',
            'search_sorted_popular' => 'Sorted by how often the titles have been borrowed.',
            'search_sorted_newest' => 'Sorted by the most recent update.',
            'label_borrowed' => ':countx borrowed',
            'biblio_detail' => 'Title #:id',
            'item_header' => 'Item :code',
            'item_available' => 'Available now',
            'item_on_loan' => 'On loan, due :due',
            'item_not_loanable' => 'Not for loan',
            'loans_header' => ':count active loan(s):',
            'loans_overdue_header' => ':count overdue loan(s):',
            'loans_returned_header' => ':count returned loan(s):',
            'loans_empty' => 'No active loans. Nothing is due.',
            'loans_overdue_badge' => ':days day(s) late',
            'member_header' => 'Member :id',
            'report_header' => 'Figures for today',
            'doctor_header' => 'CLI-Anything status',
            'preview_header' => 'Plan only, nothing written yet',
            'preview_hint' => 'Check the details, then confirm. A confirmation is valid for :ttl seconds and can be used once.',
            'committed' => 'Written and recorded in the audit log.',
            'write_error' => 'The transaction was refused: :reason',
            'confirm_expired' => 'That confirmation expired. Ask again and I will rebuild the plan.',
            'confirm_invalid' => 'That confirmation does not belong to this session or this request.',
            'rate_limited' => 'That is a lot of questions in a short time. Please wait a moment.',
            'too_long' => 'Your message is longer than :max characters. Please shorten it.',
            'error_generic' => 'Something went wrong while answering. The librarian can check the SLiMS log.',
            'error_code' => 'SLiMS answered :code: :message',
            'write_disabled' => 'Writes are disabled in config/cli_anything.php (allow_write), so I can only prepare the plan.',
            'bulk_disabled' => 'More than one record needs allow_bulk in config/cli_anything.php.',
            'ask_biblio' => 'Which title? Give me the record number, e.g. "buku #12".',
            'ask_item' => 'Which copy? Give me the item code, e.g. "cek item B00001".',
            'ask_member' => 'Which member? Give me the member id, e.g. "anggota 123".',
            'ask_loan' => 'Which loan? Give me the loan number or the item code, e.g. "kembalikan B00001".',
            'ask_items_and_member' => 'Give me the member and the item code, e.g. "pinjamkan B00001 ke anggota 123".',
            'member_not_self' => 'That loan does not belong to your account.',
            'channel_confirm_hint' => 'Reply YA to write, or BATAL to cancel.',
            'channel_cancelled' => 'Cancelled. Nothing was written.',
            'channel_expired' => 'That confirmation expired. Ask again.',
            'channel_unauthorized' => 'Unauthorized.',
            'channel_missing' => 'I could not read that message. Send text only.',

            // ---- labels -------------------------------------------------
            'label_title' => 'Title',
            'label_author' => 'Author',
            'label_call_number' => 'Call number',
            'label_isbn' => 'ISBN/ISSN',
            'label_year' => 'Year',
            'label_items' => 'Copies',
            'label_available' => 'Available',
            'label_due' => 'Due',
            'label_loan_date' => 'Loaned',
            'label_renewed' => 'Renewed',
            'label_member' => 'Member',
            'label_status' => 'Status',
            'label_loan_id' => 'Loan',
            'label_type' => 'Membership',
            'label_expiry' => 'Valid until',
            'label_fines' => 'Outstanding fines',
            'label_active_loans' => 'Active loans',
            'label_overdue_loans' => 'Overdue',
            'label_items_total' => 'Items',
            'label_available_total' => 'Available',
            'label_members_total' => 'Members',
            'label_loans_today' => 'Loans today',
            'label_returns_today' => 'Returns today',
            'label_overdue_total' => 'Overdue loans',
            'label_gate_write' => 'allow_write',
            'label_gate_bulk' => 'allow_bulk',

            // ---- chips / examples ---------------------------------------
            'chips_guest' => [
                'cari buku sejarah',
                'apakah buku #12 tersedia?',
                'jam buka',
                'bantuan',
            ],
            'chips_member' => [
                'pinjaman saya',
                'denda saya',
                'cari buku sejarah',
                'jam buka',
                'bantuan',
            ],
            'chips_staff' => [
                'pinjaman terlambat',
                'ringkasan hari ini',
                'cek item B00001',
                'jam buka',
                'bantuan',
            ],
            'faq_topics' => 'Frequently asked:',
            'faq_other' => 'Other questions I can answer:',
            'faq_more' => 'Read more',
            'faq_empty' => 'The FAQ page is empty: fill config/chatbot.faq.php.',
            'ui_feedback_up' => 'Helpful',
            'ui_feedback_down' => 'Not helpful',
            'ui_feedback_thanks' => 'Thank you — noted.',
            'feedback_invalid' => 'That feedback could not be recorded.',
            'guard_refuse' => 'I cannot help with that. Please ask about the collection, membership or library services.',
            'guard_offtopic' => 'That is outside what I can answer here. A librarian can help: please contact the service desk.',
            'guard_answer_hidden' => 'That answer is not available. A librarian can help with the details.',
            'history_title' => 'Chatbot history',
            'history_none' => 'No conversations in this period yet.',
            'history_total' => ':count messages',
            'history_answered' => ':pct% answered',
            'history_unanswered' => ':count unanswered',
            'history_feedback' => ':up helpful, :down not helpful',
            'history_avg' => ':ms ms average',
            'history_latency' => ':p50 / :p95 ms (p50 / p95)',
            'llm_title' => 'Local model or OpenAI-compatible API',
            'llm_intro' => 'Optional. The chat answers on its own; this only connects a model you choose. Off by default, and no request leaves the server until it is on.',
            'llm_enabled' => 'Use a model',
            'llm_provider' => 'Provider',
            'llm_base_url' => 'Base URL',
            'llm_model' => 'Model',
            'llm_key' => 'API key',
            'llm_key_keep' => 'Leave blank to keep the saved key',
            'llm_timeout' => 'Timeout (seconds)',
            'llm_max_tokens' => 'Max tokens',
            'llm_temperature' => 'Temperature',
            'llm_verify_tls' => 'Verify TLS certificate',
            'llm_allow_remote' => 'Allow a host outside the local network',
            'llm_calls_per_minute' => 'Calls per minute',
            'llm_save' => 'Save connection',
            'llm_saved' => 'Connection saved.',
            'llm_save_failed' => 'Could not save: the base URL is not a usable http(s) address.',
            'llm_test' => 'Test connection',
            'llm_test_ok' => 'Answered in :ms ms.',
            'llm_test_failed' => 'Failed: :error',
            'llm_models' => 'Models the server offers',
            'llm_no_models' => 'The server answered but listed no models.',
            'llm_disabled' => 'The model connection is off.',
            'llm_not_configured' => 'Set a base URL and a model first.',
            'llm_remote_blocked' => 'That host is outside the local network. Tick "allow remote" if this is intended.',
            'llm_no_key' => 'No API key saved',
            'llm_key_set' => 'Key saved: :mask',
            'llm_local_hint' => 'Ollama, LM Studio, llama.cpp and vLLM all answer /v1/chat/completions. For Ollama use http://127.0.0.1:11434/v1.',
            'llm_problems' => 'Values adjusted at load: :keys',
            'llm_status' => 'Current connection',
            'llm_off_note' => 'While this is off the chat behaves exactly as before: no request, no key, no dependency.',
            'history_intents' => 'Top intents',
            'history_misses' => 'Most asked, never answered',
            'history_filter' => 'Filter',
            'history_from' => 'From',
            'history_to' => 'To',
            'history_surface' => 'Surface',
            'history_role' => 'Role',
            'history_answered_only' => 'Answered only',
            'history_unanswered_only' => 'Unanswered only',
            'history_feedback_down' => 'Marked not helpful',
            'history_search' => 'Text contains',
            'history_export' => 'Export CSV',
            'history_detail' => 'Conversation detail',
            'history_back' => 'Back to the list',
            'history_request' => 'Request',
            'history_question' => 'Question',
            'history_intent' => 'Intent',
            'history_reply' => 'Reply',
            'history_ms' => 'Latency',
            'history_page' => 'Page :page of :pages',
            'history_prev' => 'Previous',
            'history_next' => 'Next',
            'history_disabled' => 'The conversation log is switched off (log.enabled).',
            'review_title' => 'Chatbot review',
            'review_source' => 'Content: :file',
            'review_overlay' => 'Corrections: :file (version :version)',
            'review_preview' => 'Preview — nothing is saved yet',
            'review_no_match' => 'With that change the chat still would not answer. Add another keyword or change the wording.',
            'review_open' => 'Asked, never answered (30 days)',
            'review_open_none' => 'Nothing is waiting: every question in this period found an answer.',
            'review_add_keyword' => 'Add keyword',
            'review_preview_btn' => 'Preview',
            'review_new' => 'New entry',
            'review_question' => 'Question',
            'review_keywords' => 'Keywords (comma separated)',
            'review_answer' => 'Answer',
            'review_link' => 'Link (optional)',
            'review_activate_now' => 'Activate immediately',
            'review_save' => 'Save',
            'review_test' => 'Test a question',
            'review_test_btn' => 'Ask',
            'review_entries' => 'Local entries',
            'review_status' => 'Status',
            'review_active' => 'active',
            'review_draft' => 'draft',
            'review_activate' => 'Activate',
            'review_deactivate' => 'Back to draft',
            'review_hide' => 'Hide',
            'review_hidden' => 'Hidden entries',
            'review_hidden_none' => 'Nothing is hidden.',
            'review_unhide' => 'Show again',
            'review_audit' => 'Recent changes',
            'review_audit_none' => 'No changes recorded yet.',
            'review_revert' => 'Restore previous version',
            'review_reverted' => 'The previous version is back.',
            'review_saved' => 'Saved. The chat uses it immediately, no restart needed.',
            'rules_title' => 'Blocked questions and answers',
            'rules_intro' => 'Rules run before the rule table (questions) and before any reply is shown (answers). Empty by default.',
            'rules_add' => 'Add a rule',
            'rules_scope' => 'Scope',
            'rules_type' => 'Type',
            'rules_pattern' => 'Pattern',
            'rules_category' => 'Category',
            'rules_action' => 'Action',
            'rules_note' => 'Why',
            'rules_save' => 'Save rule',
            'rules_test' => 'Test a sentence',
            'rules_test_btn' => 'Test',
            'rules_test_result' => 'Rules that fire',
            'rules_no_hits' => 'No rule fires for that sentence.',
            'rules_list' => 'Rules',
            'rules_none' => 'No rules yet. The bot behaves exactly as before.',
            'rules_impact' => 'Blocks',
            'rules_impact_hint' => 'The number on the left is how many of the FAQ questions this rule also blocks. A rule that hits normal questions is too broad.',
            'rules_enable' => 'Enable',
            'rules_disable' => 'Disable',
            'rules_delete' => 'Delete',
            'rules_saved' => 'Saved.',
            'rules_deleted' => 'Rule removed.',
            'rules_guard_off' => 'The guard is switched off (guard.enabled), so these rules are not applied yet.',
            'review_block' => 'Block',
            'review_out_of_scope' => 'Out of scope',
            'review_block_hint' => 'Blocking needs guard.enabled; out-of-scope answers politely and offers the service desk.',
            'review_category' => 'Category',
            'ui_faq_note' => 'FAQ: :count entries from :file — edit that file, then run `php index.php chatbot:faq`.',
            'examples_guest' => 'e.g. "cari buku laskar pelangi" or "apakah buku #12 tersedia?"',
            'examples_member' => 'e.g. "pinjaman saya", "denda saya" or "cari buku sejarah"',
            'examples_staff' => 'e.g. "pinjaman terlambat", "ringkasan hari ini" or "cek item B00001"',
        ];
    }

    /**
     * @return array
     */
    private static function indonesian(): array
    {
        return [
            // ---- chrome -------------------------------------------------
            'ui_title' => 'Asisten perpustakaan',
            'ui_subtitle_guest' => 'Cari di katalog. Masuk ke area anggota untuk melihat pinjaman dan denda Anda sendiri.',
            'ui_subtitle_member' => 'Tanya katalog, atau tanya pinjaman dan denda Anda sendiri.',
            'ui_subtitle_staff' => 'Katalog, sirkulasi, dan angka harian. Setiap transaksi selalu meminta konfirmasi lebih dulu.',
            'ui_placeholder' => 'Tulis pertanyaan, mis. "pinjaman terlambat" atau "cari buku sejarah"',
            'ui_send' => 'Kirim',
            'ui_thinking' => 'Sedang mencari…',
            'ui_role_guest' => 'Tamu',
            'ui_role_member' => 'Anggota',
            'ui_role_staff' => 'Staf',
            'ui_login' => 'Masuk ke area anggota',
            'ui_write_off' => 'Penulisan dimatikan oleh administrator: pertanyaan tetap jalan, transaksi tidak.',
            'ui_member_write_off' => 'Perpanjangan lewat chat dimatikan oleh administrator.',
            'ui_powered' => 'Mesin yang sama dengan CLI SLiMS (CLI-Anything)',
            'ui_chips' => 'Coba salah satu:',
            'ui_network_error' => 'Koneksi gagal. Silakan coba lagi.',
            'ui_session_expired' => 'Sesi Anda berakhir. Muat ulang halaman lalu masuk kembali.',
            'ui_confirm' => 'Konfirmasi',
            'ui_cancel' => 'Batal',
            'ui_cancelled' => 'Dibatalkan. Tidak ada yang ditulis.',
            'ui_disabled' => 'Asisten perpustakaan sedang dimatikan.',
            'ui_hint_guest' => 'Anda sedang sebagai tamu. Rincian pinjaman muncul setelah masuk.',
            'ui_hint_member' => 'Jawaban dibatasi pada akun Anda sendiri.',

            // ---- intents ------------------------------------------------
            'greet_guest' => 'Selamat datang. Saya bisa mencari di katalog dan memeriksa ketersediaan judul. Coba "cari buku …" atau "apakah buku #12 tersedia?"',
            'greet_member' => 'Selamat datang kembali, :name. Saya bisa melihat pinjaman, denda, dan katalog.',
            'greet_staff' => 'Halo :name. Saya bisa mencari katalog, melihat sirkulasi, dan menampilkan angka hari ini. Transaksi baru ditulis setelah Anda konfirmasi.',
            'help' => 'Saya memahami pertanyaan pendek soal koleksi dan, tergantung siapa Anda, soal sirkulasi. Saya tidak pernah menghapus data dan tidak pernah menampilkan data anggota lain.',
            'not_understood' => 'Saya belum menangkap maksudnya. :examples',
            'need_login' => 'Rincian pinjaman hanya bisa dibuka lewat akun anggota. Silakan masuk ke area anggota, lalu tanyakan lagi.',
            'denied_guest' => 'Itu informasi staf atau anggota, jadi tidak bisa saya jawab di sini.',
            'denied_member' => 'Itu informasi staf. Silakan tanyakan ke pustakawan di meja sirkulasi.',
            'denied_role' => 'Peran Anda tidak diizinkan untuk itu.',
            'no_results' => 'Tidak ada yang cocok dengan ":query". Coba judul yang lebih pendek, nama pengarang, atau ISBN.',
            'biblio_header' => 'Ditemukan :count dari :total judul untuk ":query":',
            'biblio_header_empty' => 'Judul terbaru di katalog:',
            'biblio_header_popular' => 'Judul yang paling sering dipinjam:',
            'search_did_you_mean' => 'Tidak ada yang persis cocok dengan ":query". Mungkin maksud Anda salah satu ini?',
            'search_approximate' => 'Tidak ada yang persis cocok dengan ":query", jadi ini judul yang paling dekat.',
            'search_retry' => 'Kirim lagi judulnya, pakai kata yang lebih sedikit, atau sebut nama pengarangnya.',
            'search_tips' => 'Salah ketik tidak masalah: judul pendek, nama pengarang, atau ISBN semuanya bisa.',
            'search_sorted_popular' => 'Diurutkan dari judul yang paling sering dipinjam.',
            'search_sorted_newest' => 'Diurutkan dari yang paling baru diperbarui.',
            'label_borrowed' => ':countx dipinjam',
            'biblio_detail' => 'Judul #:id',
            'item_header' => 'Item :code',
            'item_available' => 'Tersedia sekarang',
            'item_on_loan' => 'Sedang dipinjam, jatuh tempo :due',
            'item_not_loanable' => 'Tidak untuk dipinjamkan',
            'loans_header' => ':count pinjaman aktif:',
            'loans_overdue_header' => ':count pinjaman terlambat:',
            'loans_returned_header' => ':count pinjaman dikembalikan:',
            'loans_empty' => 'Tidak ada pinjaman aktif. Tidak ada yang jatuh tempo.',
            'loans_overdue_badge' => 'terlambat :days hari',
            'member_header' => 'Anggota :id',
            'report_header' => 'Angka hari ini',
            'doctor_header' => 'Status CLI-Anything',
            'preview_header' => 'Rencana saja, belum ada yang ditulis',
            'preview_hint' => 'Periksa rinciannya, lalu konfirmasi. Konfirmasi berlaku :ttl detik dan hanya bisa dipakai sekali.',
            'committed' => 'Sudah ditulis dan tercatat di log audit.',
            'write_error' => 'Transaksi ditolak: :reason',
            'confirm_expired' => 'Konfirmasi itu sudah kedaluwarsa. Tanyakan lagi, saya akan menyusun ulang rencananya.',
            'confirm_invalid' => 'Konfirmasi itu bukan milik sesi atau permintaan ini.',
            'rate_limited' => 'Pertanyaan Anda cukup banyak dalam waktu singkat. Mohon tunggu sebentar.',
            'too_long' => 'Pesan Anda lebih dari :max karakter. Mohon dipersingkat.',
            'error_generic' => 'Ada yang gagal saat menjawab. Pustakawan bisa memeriksa log SLiMS.',
            'error_code' => 'SLiMS menjawab :code: :message',
            'write_disabled' => 'Penulisan dimatikan di config/cli_anything.php (allow_write), jadi saya hanya bisa menyiapkan rencananya.',
            'bulk_disabled' => 'Lebih dari satu record memerlukan allow_bulk di config/cli_anything.php.',
            'ask_biblio' => 'Judul yang mana? Sebutkan nomor record, mis. "buku #12".',
            'ask_item' => 'Eksemplar yang mana? Sebutkan kode item, mis. "cek item B00001".',
            'ask_member' => 'Anggota yang mana? Sebutkan ID anggota, mis. "anggota 123".',
            'ask_loan' => 'Pinjaman yang mana? Sebutkan nomor pinjaman atau kode item, mis. "kembalikan B00001".',
            'ask_items_and_member' => 'Sebutkan anggota dan kode item, mis. "pinjamkan B00001 ke anggota 123".',
            'member_not_self' => 'Pinjaman itu bukan milik akun Anda.',
            'channel_confirm_hint' => 'Balas YA untuk menulis, atau BATAL untuk membatalkan.',
            'channel_cancelled' => 'Dibatalkan. Tidak ada yang ditulis.',
            'channel_expired' => 'Konfirmasi itu kedaluwarsa. Tanyakan lagi.',
            'channel_unauthorized' => 'Tidak diizinkan.',
            'channel_missing' => 'Pesan itu tidak bisa saya baca. Kirim teks saja.',

            // ---- labels -------------------------------------------------
            'label_title' => 'Judul',
            'label_author' => 'Pengarang',
            'label_call_number' => 'Nomor panggil',
            'label_isbn' => 'ISBN/ISSN',
            'label_year' => 'Tahun',
            'label_items' => 'Eksemplar',
            'label_available' => 'Tersedia',
            'label_due' => 'Jatuh tempo',
            'label_loan_date' => 'Dipinjam',
            'label_renewed' => 'Diperpanjang',
            'label_member' => 'Anggota',
            'label_status' => 'Status',
            'label_loan_id' => 'Pinjaman',
            'label_type' => 'Jenis anggota',
            'label_expiry' => 'Berlaku sampai',
            'label_fines' => 'Denda belum dibayar',
            'label_active_loans' => 'Pinjaman aktif',
            'label_overdue_loans' => 'Terlambat',
            'label_items_total' => 'Eksemplar',
            'label_available_total' => 'Tersedia',
            'label_members_total' => 'Anggota',
            'label_loans_today' => 'Pinjam hari ini',
            'label_returns_today' => 'Kembali hari ini',
            'label_overdue_total' => 'Pinjaman terlambat',
            'label_gate_write' => 'allow_write',
            'label_gate_bulk' => 'allow_bulk',

            // ---- chips / examples ---------------------------------------
            'chips_guest' => [
                'cari buku sejarah',
                'apakah buku #12 tersedia?',
                'jam buka',
                'bantuan',
            ],
            'chips_member' => [
                'pinjaman saya',
                'denda saya',
                'cari buku sejarah',
                'jam buka',
                'bantuan',
            ],
            'chips_staff' => [
                'pinjaman terlambat',
                'ringkasan hari ini',
                'cek item B00001',
                'jam buka',
                'bantuan',
            ],
            'faq_topics' => 'Sering ditanyakan:',
            'faq_other' => 'Pertanyaan lain yang bisa saya jawab:',
            'faq_more' => 'Baca selengkapnya',
            'faq_empty' => 'Halaman FAQ masih kosong: isi config/chatbot.faq.php.',
            'ui_feedback_up' => 'Membantu',
            'ui_feedback_down' => 'Tidak membantu',
            'ui_feedback_thanks' => 'Terima kasih — sudah dicatat.',
            'feedback_invalid' => 'Umpan balik itu tidak bisa dicatat.',
            'guard_refuse' => 'Untuk hal itu saya tidak bisa membantu. Silakan tanya soal koleksi, keanggotaan, atau layanan perpustakaan.',
            'guard_offtopic' => 'Itu di luar yang bisa saya jawab di sini. Pustakawan bisa membantu: silakan hubungi meja layanan.',
            'guard_answer_hidden' => 'Jawaban itu tidak bisa ditampilkan. Pustakawan bisa membantu rinciannya.',
            'history_title' => 'Riwayat chatbot',
            'history_none' => 'Belum ada percakapan pada periode ini.',
            'history_total' => ':count pesan',
            'history_answered' => ':pct% terjawab',
            'history_unanswered' => ':count belum terjawab',
            'history_feedback' => ':up membantu, :down tidak',
            'history_avg' => 'rata-rata :ms ms',
            'history_latency' => ':p50 / :p95 ms (p50 / p95)',
            'llm_title' => 'Model lokal atau API kompatibel OpenAI',
            'llm_intro' => 'Opsional. Chat menjawab sendiri; ini hanya menghubungkan model pilihan Anda. Mati secara bawaan, dan tidak ada permintaan keluar sampai dinyalakan.',
            'llm_enabled' => 'Pakai model',
            'llm_provider' => 'Penyedia',
            'llm_base_url' => 'URL dasar',
            'llm_model' => 'Model',
            'llm_key' => 'Kunci API',
            'llm_key_keep' => 'Biarkan kosong untuk mempertahankan kunci tersimpan',
            'llm_timeout' => 'Batas waktu (detik)',
            'llm_max_tokens' => 'Token maksimum',
            'llm_temperature' => 'Temperatur',
            'llm_verify_tls' => 'Verifikasi sertifikat TLS',
            'llm_allow_remote' => 'Izinkan host di luar jaringan lokal',
            'llm_calls_per_minute' => 'Panggilan per menit',
            'llm_save' => 'Simpan koneksi',
            'llm_saved' => 'Koneksi tersimpan.',
            'llm_save_failed' => 'Tidak bisa menyimpan: URL dasar bukan alamat http(s) yang bisa dipakai.',
            'llm_test' => 'Uji koneksi',
            'llm_test_ok' => 'Menjawab dalam :ms ms.',
            'llm_test_failed' => 'Gagal: :error',
            'llm_models' => 'Model yang ditawarkan server',
            'llm_no_models' => 'Server menjawab tetapi tidak menyebutkan model.',
            'llm_disabled' => 'Koneksi model sedang mati.',
            'llm_not_configured' => 'Isi URL dasar dan model terlebih dahulu.',
            'llm_remote_blocked' => 'Host itu di luar jaringan lokal. Centang "izinkan remote" bila memang disengaja.',
            'llm_no_key' => 'Belum ada kunci API',
            'llm_key_set' => 'Kunci tersimpan: :mask',
            'llm_local_hint' => 'Ollama, LM Studio, llama.cpp, dan vLLM semuanya menjawab /v1/chat/completions. Untuk Ollama pakai http://127.0.0.1:11434/v1.',
            'llm_problems' => 'Nilai disesuaikan saat dimuat: :keys',
            'llm_status' => 'Koneksi saat ini',
            'llm_off_note' => 'Selama mati, chat berperilaku persis seperti sebelumnya: tanpa permintaan, tanpa kunci, tanpa ketergantungan.',
            'history_intents' => 'Intent terbanyak',
            'history_misses' => 'Paling sering ditanya, belum terjawab',
            'history_filter' => 'Saring',
            'history_from' => 'Dari',
            'history_to' => 'Sampai',
            'history_surface' => 'Permukaan',
            'history_role' => 'Peran',
            'history_answered_only' => 'Hanya terjawab',
            'history_unanswered_only' => 'Hanya belum terjawab',
            'history_feedback_down' => 'Ditandai tidak membantu',
            'history_search' => 'Teks memuat',
            'history_export' => 'Ekspor CSV',
            'history_detail' => 'Rincian percakapan',
            'history_back' => 'Kembali ke daftar',
            'history_request' => 'Permintaan',
            'history_question' => 'Pertanyaan',
            'history_intent' => 'Intent',
            'history_reply' => 'Jawaban',
            'history_ms' => 'Latensi',
            'history_page' => 'Halaman :page dari :pages',
            'history_prev' => 'Sebelumnya',
            'history_next' => 'Berikutnya',
            'history_disabled' => 'Log percakapan dimatikan (log.enabled).',
            'review_title' => 'Tinjau jawaban chatbot',
            'review_source' => 'Isi: :file',
            'review_overlay' => 'Perbaikan: :file (versi :version)',
            'review_preview' => 'Pratinjau — belum ada yang disimpan',
            'review_no_match' => 'Dengan perubahan itu chat tetap tidak akan menjawab. Tambah kata kunci lain atau ubah kalimatnya.',
            'review_open' => 'Ditanya, belum terjawab (30 hari)',
            'review_open_none' => 'Tidak ada yang menunggu: semua pertanyaan pada periode ini terjawab.',
            'review_add_keyword' => 'Tambah kata kunci',
            'review_preview_btn' => 'Pratinjau',
            'review_new' => 'Entri baru',
            'review_question' => 'Pertanyaan',
            'review_keywords' => 'Kata kunci (pisahkan koma)',
            'review_answer' => 'Jawaban',
            'review_link' => 'Tautan (opsional)',
            'review_activate_now' => 'Langsung aktifkan',
            'review_save' => 'Simpan',
            'review_test' => 'Uji pertanyaan',
            'review_test_btn' => 'Tanya',
            'review_entries' => 'Entri lokal',
            'review_status' => 'Status',
            'review_active' => 'aktif',
            'review_draft' => 'draf',
            'review_activate' => 'Aktifkan',
            'review_deactivate' => 'Kembali ke draf',
            'review_hide' => 'Sembunyikan',
            'review_hidden' => 'Entri tersembunyi',
            'review_hidden_none' => 'Tidak ada yang disembunyikan.',
            'review_unhide' => 'Tampilkan lagi',
            'review_audit' => 'Perubahan terakhir',
            'review_audit_none' => 'Belum ada perubahan tercatat.',
            'review_revert' => 'Kembalikan versi sebelumnya',
            'review_reverted' => 'Versi sebelumnya sudah kembali.',
            'review_saved' => 'Tersimpan. Chat langsung memakainya, tanpa restart.',
            'rules_title' => 'Blokir pertanyaan dan jawaban',
            'rules_intro' => 'Aturan dijalankan sebelum tabel intent (pertanyaan) dan sebelum jawaban ditampilkan (jawaban). Bawaannya kosong.',
            'rules_add' => 'Tambah aturan',
            'rules_scope' => 'Cakupan',
            'rules_type' => 'Jenis',
            'rules_pattern' => 'Pola',
            'rules_category' => 'Kategori',
            'rules_action' => 'Aksi',
            'rules_note' => 'Alasan',
            'rules_save' => 'Simpan aturan',
            'rules_test' => 'Uji satu kalimat',
            'rules_test_btn' => 'Uji',
            'rules_test_result' => 'Aturan yang kena',
            'rules_no_hits' => 'Tidak ada aturan yang kena untuk kalimat itu.',
            'rules_list' => 'Aturan',
            'rules_none' => 'Belum ada aturan. Perilaku bot sama seperti sebelumnya.',
            'rules_impact' => 'Memblokir',
            'rules_impact_hint' => 'Angka di kiri adalah berapa pertanyaan FAQ yang juga ikut diblokir aturan ini. Aturan yang mengenai pertanyaan normal terlalu luas.',
            'rules_enable' => 'Aktifkan',
            'rules_disable' => 'Matikan',
            'rules_delete' => 'Hapus',
            'rules_saved' => 'Tersimpan.',
            'rules_deleted' => 'Aturan dihapus.',
            'rules_guard_off' => 'Guard dimatikan (guard.enabled), jadi aturan ini belum diterapkan.',
            'review_block' => 'Blokir',
            'review_out_of_scope' => 'Di luar cakupan',
            'review_block_hint' => 'Memblokir butuh guard.enabled; di luar cakupan menjawab dengan sopan dan menawarkan meja layanan.',
            'review_category' => 'Kategori',
            'ui_faq_note' => 'FAQ: :count entri dari :file — sunting berkas itu, lalu jalankan `php index.php chatbot:faq`.',
            'examples_guest' => 'mis. "cari buku laskar pelangi" atau "apakah buku #12 tersedia?"',
            'examples_member' => 'mis. "pinjaman saya", "denda saya", atau "cari buku sejarah"',
            'examples_staff' => 'mis. "pinjaman terlambat", "ringkasan hari ini", atau "cek item B00001"',
        ];
    }
}
