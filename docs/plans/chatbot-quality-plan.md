# Rencana: kualitas jawaban chatbot, riwayat, perbaikan, dan pemblokiran

**Status:** draf untuk persetujuan. Dokumen ini belum mengubah satu baris kode pun.
**Konteks:** PR #1 (`arena/dbd7cd5a-slims9-bulian`) sudah berisi mesin chat, pencarian bertingkat, FAQ berbasis berkas, kanal WhatsApp/Telegram, dan 111 asersi offline.
**Masalah pokok:** suite sekarang menguji *perilaku yang sudah kita pikirkan*, bukan *akurasi pada pertanyaan nyata*. Belum ada riwayat, belum ada cara memperbaiki jawaban yang salah tanpa menyunting PHP, dan belum ada cara memblokir pertanyaan atau jawaban yang tidak boleh keluar.

---

## Status implementasi (diperbarui 2026-11-27)

Seluruh fase sudah dikerjakan di branch PR #1, dengan penyimpangan kecil yang
dicatat di bawah.

| Fase | Status | Bukti |
|---|---|---|
| 0 — riwayat | selesai | `Support/LogStore.php`, `Commands/ChatbotLog.php`, `log` block |
| 1 — halaman riwayat | selesai | `Support/History.php`, `pages/history.php`, menu System |
| 2 — perbaikan | selesai | `Support/Overlay.php`, `pages/review.php`, feedback 👍/👎 |
| 3 — blokir | selesai | `Support/Guard.php`, `pages/rules.php`, saringan jawaban |
| 4 — pengukuran | selesai | `tests/corpus.php` (124 kalimat, 63 di antaranya non-FAQ), `Support/Evaluation.php`, `Commands/ChatbotEval.php` |
| 5 — uji lapangan | selesai kecuali e2e berdata | papan angka mingguan (`p50`/`p95`, guard hits, 10 tak terjawab teratas) selesai; `Commands/ChatbotE2e.php` tersedia tetapi belum dijalankan terhadap DB sungguhan |

Tes offline naik dari **111 menjadi 185 asersi**. Korpus berisi **124
kalimat**: 61 mengharapkan jawaban FAQ (Indonesia dan Inggris) dan 63 menuntut
mesin *tidak* menjawabnya lewat FAQ (pencarian, data anggota, sapaan, atau
"tidak ada jawaban"). Akurasi 1.0, toleransi salah ketik 0.964 pada 168 varian,
paritas bahasa dan kontrak tampilan tanpa temuan.

### Pengerasan setelah fase 5

Empat hal yang tidak ada di sketsa awal tetapi muncul saat menutup fase:

1. `Config::sanitise()` memperbaiki konfigurasi yang salah ketik (ambang FAQ
   non-numerik berubah menjadi 0.0 dan membuat FAQ menjawab segalanya), dengan
   daftar perbaikan yang dicetak `chatbot:selftest`.
2. Badan permintaan dibatasi 256 KB dan kedalaman JSON 32; webhook menjawab
   413 `PAYLOAD_TOO_LARGE` tanpa mendekode apa pun.
3. Pesan pengecualian dari lapisan basis data hanya ditampilkan ke staf;
   pengunjung menerima kalimat umum, kodenya tetap masuk log.
4. Jawaban webhook yang gagal tetap membawa `error.code`, bukan respons kosong
   yang tampak berhasil.

### Penyimpangan yang perlu diketahui

1. **Nama flag staf** memakai kunci datar `admin_history`, `admin_overlay`,
   `admin_rules`, bukan `admin.history`. `admin` sudah dipakai sebagai saklar
   permukaan chat, jadi blok bersarang akan merusak `Config::enabled('admin')`.
2. **Teks jawaban tidak disimpan** di log, hanya intent, entri FAQ, dan
   latensi. Menyimpan jawaban penuh menggandakan risiko PII dan ukuran berkas;
   halaman detail menampilkan apa yang tercatat, bukan transkrip balasan.
3. **Retensi dihitung per bulan**, bukan per baris: berkas bulan yang lebih tua
   dari `log.retention_days` dihapus. Rotasi ukuran membuat berkas bulan
   berjalan terpecah tanpa menghapus rekaman terbaru.
4. **Sweep ambang di suite** dijalankan pada tiga titik (0.56/0.62/0.68) agar
   tetap cepat; sweep penuh 0.40–0.80 tersedia lewat `chatbot:eval --sweep`.
5. **`hide` hanya untuk aturan jawaban**; aturan pertanyaan memakai `refuse`
   atau `fallback`. Ini ditegakkan saat validasi.
6. **Uji E2E live** berbentuk perintah `chatbot:e2e` (baca-saja, butuh
   `CHATBOT_E2E=1`), bukan skrip docker terpisah; menjalankannya butuh
   pemasangan SLiMS berdata.

## 1. Masalah yang diselesaikan

| Gejala | Penyebab sekarang | Fase |
|---|---|---|
| "Chatbot tidak bisa jawab" tapi tidak ada buktinya | tidak ada log percakapan | 0, 1 |
| Jawaban salah, pustakawan tidak bisa membetulkan | isi FAQ = berkas PHP, harus diedit manual | 2 |
| Salah ketik/typo pada kata kunci lolos atau justru salah tangkap | ambang 0.62 dipilih tangan, tanpa data | 4 |
| Pertanyaan tidak sopan, spam, atau upaya menyuruh bot keluar topik | tidak ada lapisan penjaga sebelum aturan intent | 3 |
| Entri FAQ berisi janji/kebijakan yang tidak boleh diucapkan | tidak ada status draf dan tidak ada penyaring isi | 2, 3 |
| Regresi diam-diam saat aturan intent diubah | 111 asersi spot-check, tanpa korpus | 4 |
| "Jalan di DB sungguhan" masih berupa risiko di README | belum ada uji end-to-end berdata | 5 |

## 2. Definisi "teruji valid"

Empat ukuran, semuanya bisa dihitung dan ditampilkan:

1. **Benar** — akurasi pada korpus ≥120 kalimat (Indonesia + Inggris, tiga peran): ≥95% untuk kasus yang harus dijawab, **0 kesalahan** pada kasus negatif ("pinjamkan B00001 ke anggota 123" tidak boleh jadi jawaban FAQ).
2. **Jujur** — bila tidak yakin, bot bilang tidak tahu dan menawarkan langkah berikutnya; tidak mengarang. Diukur: proporsi fallback yang *tetap* membantu (ada chip/tautan) = 100%.
3. **Tidak pernah terlarang** — 0 jawaban yang cocok dengan daftar jawaban terlarang; 100% pertanyaan yang diblokir ditolak dengan pesan sopan, bukan error.
4. **Dapat diperbaiki** — setiap jawaban yang salah punya jalur: log → tinjau → tambah kata kunci/entri/blokir → uji ulang, tanpa deploy.

## 3. Batasan dan prinsip

* Tidak menyentuh kode inti SLiMS; semua di `plugins/`, `config/`, `docs/`.
* **Tanpa migrasi skema.** Tidak ada `ALTER TABLE`, tidak ada tabel baru. Riwayat, aturan, dan overlay disimpan sebagai berkas di `files/chatbot/`.
* PII disamarkan; tidak ada data yang dihapus (blokir = menyembunyikan, bukan menghapus).
* Tetap jalan di repo vanilla, PHP 7.4+ dan 8.x, tanpa ekstensi tambahan (tanpa `mbstring`).
* UI tanpa framework JS baru; mengikuti gaya `assets/chatbot.js` yang ada.
* Semua fitur baru punya flag di `config/chatbot.php`; mati = perilaku persis seperti sekarang.
* Berkas tulis selalu atomik (`tmp` + `rename` + lock), diawali `<?php exit; ?>`, dan divalidasi sebelum disimpan.

## 4. Arsitektur: satu alur, empat titik sisip

Sekarang:

```
pesan → Intent::classify → (FAQ::match) → Policy::allows → dispatch → respond
```

Setelah rencana ini:

```
pesan → Guard::check ──(blokir)──→ tolak lokal (i18n) ─┐
      → Intent::classify → FAQ::match (isi + overlay)  │
      → Policy::allows → dispatch → respond            │
      → LogStore::record ←────────────────────────────-┘
      ↑ feedback (👍/👎) menempel pada record yang sama
```

Titik sisip:

| Titik | Berkas | Sifat |
|---|---|---|
| `Guard::check()` sebelum klasifikasi | baru `Support/Guard.php` | menyaring, tidak menjawab topik |
| `Faq::items()` menggabungkan overlay | `Support/Faq.php` | baca-saja untuk berkas asli |
| `Http::process()` dan `Webhook::dispatch()` merekam | `Support/Http.php`, `Support/Webhook.php` | satu jalur untuk OPAC, admin, kanal, CLI |
| Halaman admin baru | `pages/history.php`, `pages/review.php` | staf saja |

## 5. Fase 0 — Fondasi riwayat (log)

**Tujuan:** setiap percakapan punya jejak yang bisa ditinjau, tanpa membebani DB dan tanpa menyimpan data pribadi berlebih.

### 5.1 Satu rekaman (NDJSON, satu baris per pesan)

| Field | Isi | Catatan |
|---|---|---|
| `ts` | waktu ISO-8601 | zona waktu situs |
| `req` | 8 hex | id permintaan, untuk korelasi dengan log web |
| `surface` | `opac`, `admin`, `whatsapp`, `telegram`, `cli` | dari `Http::process`/`Webhook` |
| `role` | `guest`, `member`, `staff` | bukan id |
| `actor` | `sha1(salt + id)` | salt di `files/chatbot/.salt` (0600), bukan id asli |
| `locale` | `id`, `en` | |
| `q` | pesan, dipotong 300 karakter | bisa dimatikan → hanya hash |
| `q_hash` | `sha1(pesan ternormalisasi)` | untuk menghitung pertanyaan berulang |
| `intent` | nama intent atau `none` | |
| `faq_id` | entri FAQ bila menjawab | |
| `stage` | `exact`, `prefix`, `suggestion`, `empty`, `faq`, `guard` | |
| `ms` | durasi | angka, bukan teks |
| `write` | `true` bila intent menulis | |
| `confirm` | `planned`, `committed`, `cancelled`, `expired` | |
| `guard` | id aturan yang menolak | null bila lolos |
| `feedback` | `null`, `up`, `down`, plus waktu | diisi belakangan |
| `err` | kode error layanan | tanpa stack trace |

Tidak ada: nama anggota, nomor telepon, isi surel, IP mentah (hanya `sha1`), token konfirmasi.

### 5.2 Penyimpanan

* Satu berkas per bulan: `files/chatbot/log-YYYYMM.ndjson.php`, diawali `<?php exit; ?>`.
* Rotasi saat berkas >5 MB (`log-YYYYMM.ndjson.php.1`), maksimum 6 bagian; yang lebih tua dipangkas.
* Retensi bawaan **90 hari** (`log.retention_days`), pemangkasan saat menulis dan lewat perintah.
* Tulis dengan `flock` + `FILE_APPEND`; kegagalan menulis log **tidak boleh** menggagalkan jawaban.

### 5.3 Konfigurasi

```php
'log' => [
    'enabled' => true,
    'dir' => '',              // kosong = SB files/chatbot
    'retention_days' => 90,
    'max_bytes' => 5242880,
    'store_message' => true,  // false = hanya hash, tanpa teks
    'truncate' => 300,
    'feedback' => true,
],
```

### 5.4 Perintah

| Perintah | Guna |
|---|---|
| `php index.php chatbot:log --days=7` | ringkasan: jumlah pesan, % terjawab, % fallback, intent teratas |
| `php index.php chatbot:log --unanswered --limit=50` | daftar yang tidak terjawab |
| `php index.php chatbot:log --prune` | jalankan retensi sekarang |
| `php index.php chatbot:log --json` | untuk skrip/CI |

### 5.5 Tes dan kriteria selesai

* Format rekaman: kunci wajib ada, tidak ada id mentah, pesan terpotong, `q_hash` stabil.
* Rotasi: menulis berkas kecil dengan `max_bytes` dikecilkan → rotasi terjadi, jumlah bagian ≤ batas.
* Retensi: rekaman 200 hari hilang setelah `--prune`; rekaman 10 hari tetap.
* Kegagalan tulis (direktori read-only) → jawaban tetap `ok: true`, ada penanda di `meta`.
* Perkiraan: **+12 asersi**.

## 6. Fase 1 — Halaman admin "Riwayat Chatbot"

**Tujuan:** staf bisa menjawab "apa saja yang ditanyakan minggu ini, mana yang gagal, kenapa".

* Menu baru **System → Chatbot: riwayat** (`registerMenu('system', ...)`), aktif bila `admin.history` benar; halaman chat lama mendapat tautan tab.
* Saringan: rentang tanggal (bawaan 7 hari), surface, peran, intent, `faq_id`, hanya yang belum terjawab, hanya feedback 👎, pencarian teks (hanya bila `store_message`).
* Daftar: 50 baris per halaman; kolom waktu, peran, pesan (dipotong), intent, hasil, ms, feedback.
* Detail: pesan penuh (redaksi), intent + confidence + parameter (id anggota disamarkan), jawaban yang dikirim, aturan guard/FAQ yang dipakai, dan tombol aksi Fase 2.
* Ekspor CSV (maksimum 5.000 baris) dengan kolom yang sama; tanpa data pribadi.
* Hanya staff dengan sesi admin; tidak ada akses dari OPAC atau kanal.
* i18n Indonesia + Inggris; tabel memakai pola `table` yang sudah ada di halaman admin lain.

**Selesai bila:** staf bisa menemukan satu percakapan bermasalah dalam ≤3 klik dari menu, dan tes membuktikan guest tidak bisa membuka halaman/endpoint ini (403), sedangkan staff bisa.

*Perkiraan: +14 asersi.*

## 7. Fase 2 — Perbaikan jawaban yang salah atau tidak terjawab

**Tujuan:** pustakawan memperbaiki perilaku bot dari UI, tanpa menyunting PHP dan tanpa deploy.

### 7.1 Masukan dari pengguna

* Tombol 👍/👎 pada setiap jawaban di OPAC (anonim) dan perintah balasan di kanal (`/salah`, `/benar`).
* Feedback menempel pada `req` di log, bukan percakapan baru.

### 7.2 Antrean tinjauan

Status rekaman: `baru` → `ditinjau` → `selesai` atau `diabaikan`, disimpan di berkas terpisah (`review-YYYYMM.ndjson.php`) agar log tetap hanya-baca.

Saringan cepat: "belum terjawab", "feedback 👎", "guard menolak", "FAQ skor rendah (0.62–0.70)".

### 7.3 Tindakan yang tersedia untuk staf

| Tindakan | Efek | Aman karena |
|---|---|---|
| **Tambah kata kunci** | kata/frasa baru untuk entri FAQ yang ada | hanya menambah ke overlay, bukan mengubah berkas asli |
| **Buat entri FAQ** | entri baru (pertanyaan, kata kunci, jawaban, `link`) | divalidasi `Faq::validate()`, disimpan sebagai `draft` sampai diaktifkan |
| **Sembunyikan entri** | entri tidak lagi dijawab | tidak menghapus isi berkas |
| **Blokir pertanyaan** | pola masuk daftar Guard (Fase 3) | dengan alasan dan kategori wajib |
| **Tandai di luar cakupan** | bot menjawab "belum bisa" + menawarkan kontak | hanya memengaruhi pertanyaan itu |
| **Usulkan aturan intent** | menghasilkan diff untuk pengembang, bukan tulisan otomatis | aturan intent tetap di kode |

Setiap tindakan punya **pratinjau**: "uji pertanyaan ini sekarang" menjalankan mesin dengan overlay baru sebelum disimpan, dan menampilkan jawaban yang akan keluar.

### 7.4 Overlay FAQ

`files/chatbot/faq.local.php` (di luar webroot efektif, diawali `<?php exit; ?>`):

```php
return [
    'keywords' => ['hours' => ['buka sabtu', 'jam layanan']],
    'entries' => [ /* entri baru, masing-masing status draft|active */ ],
    'hidden' => ['donation'],
    'updated_at' => '2026-11-27T10:00:00+07:00',
];
```

* `Faq::items()` membaca berkas isi lalu menerapkan overlay (kata kunci tambahan, entri aktif, entri tersembunyi).
* Penulisan: kunci eksklusif, tulis ke `.tmp`, validasi, `rename`, simpan `.bak` terakhir.
* Audit terpisah `audit.ndjson.php`: siapa, kapan, tindakan, hash sebelum/sesudah — tanpa isi lengkap.
* Semua perubahan bisa dibatalkan satu klik ("kembalikan versi sebelumnya"; hanya `.bak` terakhir untuk versi pertama).

### 7.5 Tes dan kriteria selesai

* Overlay dibaca tanpa mengubah berkas asli (sha1 berkas asli tetap).
* Entri `draft` tidak pernah dijawab; setelah `active` langsung dijawab tanpa restart.
* Penulisan gagal di tengah (simulasi `rename` gagal) tidak merusak berkas lama.
* Pratinjau = hasil setelah simpan (dijalankan pada dua keadaan yang sama).
* Staf tanpa hak tidak bisa menulis overlay (403); CSRF wajib.
* Perkiraan: **+20 asersi**.

## 8. Fase 3 — Blokir pertanyaan dan jawaban terlarang

**Tujuan:** bot tidak pernah membahas hal yang sudah diputuskan perpustakaan untuk tidak dibahas, dan tidak pernah mengeluarkan kalimat terlarang — termasuk bila isi FAQ keliru.

### 8.1 Dua cakupan aturan

| Cakupan | Kapan diperiksa | Contoh |
|---|---|---|
| `question` | sebelum klasifikasi intent | kata kasar, spam tautan, upaya menyuruh bot menjadi hal lain, topik sensitif yang bukan layanan perpustakaan |
| `answer` | saat memuat isi FAQ dan sebelum mengirim | janji bebas denda, nomor pribadi, tautan luar yang tidak resmi, klaim medis/hukum |

### 8.2 Bentuk aturan

```php
[
    'id' => 'g-001',
    'scope' => 'question',        // question | answer
    'type' => 'phrase',           // phrase | word | regex
    'pattern' => 'bebas denda',
    'category' => 'policy',       // abuse | spam | injection | policy | legal | medical | off-topic
    'action' => 'refuse',         // refuse | fallback | hide (answer)
    'message' => 'guard_refuse',  // kunci Lang, bukan teks bebas
    'enabled' => true,
    'note' => 'kebijakan kepala perpustakaan, 2026-11',
],
```

* Berkas aturan: `files/chatbot/guard.php`, dikelola dari **System → Chatbot: aturan**.
* Bawaan: **kosong dan inert** — pemasangan baru berperilaku sama seperti sekarang.
* `answer` + `hide`: entri yang cocok disembunyikan dari hasil dan dilaporkan `chatbot:faq --strict` supaya isinya dibetulkan.
* Jawaban penolakan memakai kunci bahasa baru `guard_refuse`, `guard_offtopic`, `guard_answer_hidden` (ID + EN).

### 8.3 Anti-bypass

Normalisasi sebelum pencocokan: huruf kecil, hapus karakter tak terlihat (zero-width), rapatkan spasi, buang tanda baca, petakan angka pengganti huruf (`0`→`o`, `1`→`i`, `3`→`e`) — memakai `Str` yang sudah ada, tanpa `mbstring`.

Batas regex: panjang pola ≤120 karakter, wajib lolos kompilasi saat disimpan, dilarang backreference rekursif, subjek dipotong sebelum `preg_match`, dan hasil `false` (error) diperlakukan sebagai **tidak cocok** dengan peringatan di log — bukan fatal.

### 8.4 Tata kelola

* Setiap aturan wajib punya `note` (alasan) dan tercatat di audit.
* Uji "tidak over-blocking": 200 kalimat normal dari korpus harus tetap lolos; bila satu aturan memblokir >1% kalimat normal, UI memberi peringatan.
* Tombol "uji aturan" di UI: tempel kalimat → lihat apakah kena, dan aturan mana.

### 8.5 Tes dan kriteria selesai

* Kata kasar dengan variasi spasi/tanda baca/angka tetap tertangkap.
* Kalimat normal yang mengandung kata yang sama (mis. "buku tentang kekerasan") **tidak** ikut diblokir bila aturan ditulis sebagai frasa.
* Entri FAQ dengan jawaban terlarang hilang dari hasil, dan `chatbot:faq --strict` keluar non-zero.
* Guard mati → perilaku identik dengan sebelumnya.
* Perkiraan: **+18 asersi**.

## 9. Fase 4 — Pengukuran: korpus, ambang, fuzz, paritas

Ini bagian yang membuat "teruji valid" punya angka, dan yang mengisi korpus dari riwayat.

| Butir | Isi | Target |
|---|---|---|
| Korpus | `tests/corpus.php`: ≥120 kalimat (ID/EN, tiga peran), termasuk 40 negatif | akurasi ≥95%, negatif 0 salah |
| Sweep ambang | 0.40→0.80 langkah 0.02 di atas korpus | precision ≥0.95 pada 0.62; 0.62 tidak di tepi jurang |
| Fuzz typo | varian 1-edit (hapus/transposisi/ganda) dari kata kunci, seed tetap | ≥90% tetap cocok |
| Fuzz negatif | 200 kalimat acak non-FAQ | false positive <2% |
| Paritas bahasa | kunci `Lang` sama di id/en, placeholder sama | 100% |
| Kontrak render | 10 alur: kunci blok lengkap, tanpa `:param` tersisa, tanpa HTML mentah | 100% |
| Perintah | `php index.php chatbot:eval [--json] [--sweep]` | exit non-zero bila target gagal |

Korpus tumbuh dari riwayat: `chatbot:log --unanswered` → tinjau → tambahkan kasus ke korpus, bukan hanya ke FAQ. Ini yang mencegah "sudah diperbaiki" berubah lagi tanpa ketahuan.

*Perkiraan: +25 asersi.*

## 10. Fase 5 — Uji lapangan dan observabilitas

* **E2E berdata:** `docker-compose` + data contoh, 12 skrip dialog per peran (cari → detail → pinjam → perpanjang → kembalikan → denda), hasil dibandingkan dengan baris yang diketahui. Hanya jalan dengan `CLI_ANYTHING_E2E=1`, bukan CI bawaan.
* **Papan angka mingguan:** % terjawab, 10 pertanyaan tak terjawab teratas, feedback 👎, guard hits, latensi p50/p95. Ditampilkan di halaman riwayat.
* **Ambang mutu rilis:** rilis dianggap siap bila korpus hijau, `chatbot:faq --strict` bersih, tidak ada penolakan guard pada kalimat normal.

*Perkiraan: +15 asersi.*

## 11. Berkas dan perintah baru

| Berkas | Fase | Isi |
|---|---|---|
| `Support/LogStore.php` | 0 | tulis/baca/putar/pangkas NDJSON |
| `Support/Guard.php` | 3 | normalisasi, pencocokan, aksi |
| `Support/Overlay.php` | 2 | baca/tulis/validasi overlay FAQ + audit |
| `pages/history.php` | 1 | daftar + detail riwayat |
| `pages/review.php` | 2 | antrean tinjauan + pratinjau |
| `pages/rules.php` | 3 | kelola aturan blokir |
| `Commands/ChatbotLog.php` | 0 | `chatbot:log` |
| `Commands/ChatbotEval.php` | 4 | `chatbot:eval` |
| `tests/corpus.php` | 4 | data kasus, bukan kode |
| `files/chatbot/{log,review,audit}-YYYYMM.ndjson.php` | 0–2 | data, di luar git |
| `files/chatbot/{faq.local,guard}.php` | 2–3 | aturan dan tambahan isi |

Semua berkas data berada di luar Git dan di luar webroot efektif; `.gitignore` sudah menutup `files/`.

## 12. Keamanan, privasi, kepatuhan

| Risiko | Mitigasi |
|---|---|
| Log berisi pertanyaan pribadi | potong 300 karakter, tanpa nama/nomor, `store_message` bisa dimatikan menjadi hash saja |
| Log membesar | rotasi 5 MB, 6 bagian, retensi 90 hari |
| Berkas data diakses lewat HTTP | diawali `<?php exit; ?>`, ekstensi `.php`, direktori tidak diindeks |
| Staf menyalahgunakan penyuntingan | audit per perubahan, izin staf saja, CSRF, validasi wajib |
| Guard jadi alat menutup kritik | semua aturan wajib beralasan dan tercatat; halaman aturan menampilkan "diblokir apa" secara terbuka bagi staf |
| Regex jahat/ReDoS | batas panjang pola, kompilasi wajib, subjek dipotong, error = tidak cocok |
| Log dipakai memantau pengguna | hanya `sha1(actor + salt)`, tanpa IP; retensi pendek |
| Kehilangan data saat menulis | tulis atomik + `.bak`, overlay tidak pernah menyentuh berkas asli |

## 13. Rencana pengujian

| Fase | Asersi baru | Fokus |
|---|---|---|
| 0 | +12 | format, rotasi, retensi, gagal-tulis |
| 1 | +14 | izin, saringan, paginasi, redaksi |
| 2 | +20 | overlay, draft/aktif, pratinjau=hasil, CSRF |
| 3 | +18 | anti-bypass, over-blocking, guard mati |
| 4 | +25 | korpus, sweep, fuzz, paritas, kontrak |
| 5 | +15 | e2e opt-in, papan angka |
| **Total** | **111 → ±215** | semua offline tetap tanpa DB |

## 14. Rollout dan rollback

* Urutan: 0 → 1 → 2 → 3 → 4 → 5; setiap fase bisa dirilis sendiri.
* Flag baru bawaan: `log.enabled=true`, `log.feedback=true`, `admin.history=true`, `admin.overlay=true`, `guard.enabled=false`.
* Rollback satu fase: matikan flag → perilaku kembali; berkas data dibiarkan (tidak dihapus).
* Rollback total: hapus `files/chatbot/log-*`, `faq.local.php`, `guard.php`; plugin kembali ke perilaku PR #1.

## 15. Estimasi

| Fase | Ukuran | Perkiraan |
|---|---|---|
| 0 | M | 1–1,5 hari |
| 1 | M | 1–1,5 hari |
| 2 | L | 2–3 hari |
| 3 | M | 1,5–2 hari |
| 4 | L | 2 hari |
| 5 | M | 1–2 hari |
| **Total** | | **9–12 hari kerja** |

Bila waktu terbatas, urutan nilai tertinggi lebih dulu: **0 + 1** (bisa melihat masalah nyata), lalu **4** (mengunci mutu dengan angka), baru **2 + 3**.

## 16. Keputusan yang perlu Anda setujui

| # | Pertanyaan | Rekomendasi |
|---|---|---|
| 1 | Riwayat di berkas atau di tabel `system_log`? | berkas NDJSON — tanpa skema, mudah dipangkas, tidak memperlambat MyISAM |
| 2 | Simpan potongan pesan (300 karakter) atau hanya hash? | simpan potongan, `store_message` bisa dimatikan |
| 3 | Retensi bawaan? | 90 hari |
| 4 | Admin boleh menulis FAQ dari UI? | ya, tetapi hanya overlay (tambah kata kunci, entri baru berstatus draf, sembunyikan) — berkas isi asli tidak pernah diubah dari web |
| 5 | Regex diizinkan di aturan blokir? | ya, dengan batas 120 karakter dan uji kompilasi; bila ingin paling aman, versi pertama cukup frasa dan kata |
| 6 | Tombol 👍/👎 untuk tamu? | ya, anonim, tanpa teks bebas |
| 7 | Perlu halaman docs pengguna untuk riwayat + perbaikan? | ya, `03_quality.md`, menyusul saat Fase 1 dan 2 selesai |

## 17. Tidak termasuk (non-goals)

* LLM, embedding, atau pencarian semantik — tetap deterministik dan bisa diulang di terminal.
* Mengubah aturan intent dari UI; UI hanya mengusulkan diff untuk pengembang.
* Migrasi skema, tabel baru, atau menulis ke tabel inti selain `system_log` yang sudah dipakai audit transaksi.
* Menghapus data apa pun; blokir dan sembunyikan bersifat menonaktifkan.
* Multi-tenant atau beberapa perpustakaan dalam satu pemasangan.
* Analitik lintas pengguna yang dapat mengidentifikasi individu.

---

**Langkah berikutnya bila disetujui:** Fase 0 + 1 dalam satu PR kecil (log, perintah `chatbot:log`, halaman riwayat, +26 asersi), lalu Fase 4 agar angkanya terkunci sebelum menambah fitur tulis.
