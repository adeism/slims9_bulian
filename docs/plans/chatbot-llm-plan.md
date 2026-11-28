# Rencana: koneksi LLM lokal / API kompatibel OpenAI

**Status:** fase 1 selesai (pengaturan + koneksi + uji). Fase 2 menunggu keputusan pemakaian (§7).
**Konteks:** PR #1 (`arena/dbd7cd5a-slims9-bulian`). Chatbot sekarang deterministik: intent, FAQ, dan CLI-Anything. Tidak ada panggilan jaringan keluar sama sekali.

## 1. Masalah

Perpustakaan yang ingin jawaban lebih luwes ("jelaskan ulang dengan bahasa saya")
atau ingin memakai model lokal (Ollama, LM Studio, llama.cpp, vLLM) tidak punya
tempat untuk mengaturnya. Menempelkan URL di dalam kode berarti kunci API
tersebar di berkas yang salah dan tidak ada cara mengujinya.

## 2. Keputusan yang mengikat

1. **LLM tidak pernah menggantikan jalur deterministik.** Perintah, pencarian,
   sirkulasi, dan FAQ tetap dijawab oleh mesin yang sudah diuji. LLM hanya
   dipakai bila tidak ada jawaban deterministik, dan itu pun opsional.
2. **Bawaannya mati.** `llm.enabled=false`. Pemasangan/pembaruan tidak
   mengubah perilaku, tidak membuka koneksi, tidak menambah ketergantungan.
3. **Satu bentuk API.** Semua penyedia yang didukung berbicara
   `POST {base_url}/chat/completions` dan `GET {base_url}/models` (OpenAI,
   Ollama `/v1`, LM Studio, llama.cpp server, vLLM, LocalAI, OpenRouter).
4. **Tidak ada data pribadi di prompt.** Hanya pertanyaan pengguna (dipotong),
   bahasa, dan — bila dipakai sebagai bahan — entri FAQ/katalog yang sudah
   publik. Tidak ada id anggota, hash aktor, atau isi pinjaman.
5. **Guard tetap berlaku.** Pertanyaan disaring sebelum LLM; jawaban hasil LLM
   disaring aturan `answer` sebelum ditampilkan.
6. **Gagal = kembali ke perilaku lama.** Timeout, 401, 429, JSON rusak, atau
   server mati tidak boleh menggagalkan jawaban: mesin deterministik menjawab
   seperti LLM tidak ada, dan kegagalannya tercatat di log percakapan.

## 3. Fase

| Fase | Isi | Hasil |
|---|---|---|
| 1 — pengaturan & koneksi | blok `llm` di config, overlay `files/chatbot/llm.php`, `Support/Llm.php` (klien + validasi + SSRF), halaman **System → Chatbot LLM**, perintah `chatbot:llm --test/--models` | administrator bisa mengatur dan menguji koneksi tanpa menyentuh kode |
| 2 — pemakaian | jawaban LLM hanya untuk `intent:none`/tanpa FAQ, dengan guard + fallback + circuit breaker + batas per menit | jawaban lebih luwes tanpa mengorbankan jalur yang diuji |
| 3 — pengukuran | asersi: LLM mati = jawaban identik; prompt tanpa PII; output disaring guard; kegagalan tidak memperlambat jawaban | angka, bukan klaim |

Fase 1 selesai: `Support/Llm.php`, `pages/llm.php`, `Commands/ChatbotLlm.php`, blok `llm` di config, 11 asersi baru (suite 196). Fase 2 menunggu keputusan pemakaian (lihat §7).

## 4. Konfigurasi

`config/chatbot.php` boleh memuat blok ini, dan halaman admin menulis nilai
yang sama ke `files/chatbot/llm.php` (0600, di luar git, diawali `<?php exit; ?>`).

| Kunci | Bawaan | Arti |
|---|---|---|
| `enabled` | `false` | saklar utama; mati = tidak ada panggilan jaringan |
| `provider` | `openai` | label tampilan (semua OpenAI-compatible) |
| `base_url` | `http://127.0.0.1:11434/v1` | akar API, tanpa `/chat/completions` |
| `model` | `''` | nama model, mis. `llama3.1:8b`, `gpt-4o-mini` |
| `api_key` | `''` | opsional untuk server lokal; disimpan 0600, ditampilkan tersamar |
| `timeout` | `15` | detik, 3–120 |
| `max_tokens` | `256` | 16–4096 |
| `temperature` | `0.2` | 0–2 |
| `verify_tls` | `true` | jangan dimatikan untuk API publik |
| `allow_remote` | `false` | wajib `true` untuk host di luar jaringan lokal |
| `max_calls_per_minute` | `6` | batas panggilan per sesi (dipakai di fase 2) |

## 5. Keamanan

* **SSRF.** Hanya `http`/`https`, tanpa userinfo/query/fragment. Host lokal
  (`127.0.0.1`, `::1`, `localhost`, `10/8`, `172.16/12`, `192.168/16`, `*.local`)
  boleh; host lain wajib `allow_remote=true`. Alamat metadata cloud
  (`169.254.169.254`, `metadata.google.internal`) selalu ditolak.
* **Kunci API** tidak pernah masuk log, tidak pernah dikirim balik ke browser,
  dan hanya ditampilkan sebagai `••••abcd`. Formulir kosong = kunci lama
  dipertahankan.
* **Batas respons** 256 KB dan `max_tokens` mencegah server nakal mengirim
  data tanpa batas. `follow_location` dimatikan agar tidak ada redirect ke
  host lain.
* **Audit.** Setiap penyimpanan menulis baris ke `audit.ndjson.php` (siapa,
  kapan, host+model, bukan kunci).

## 6. Pengujian (offline, tanpa jaringan)

Transport dapat disuntik (`Llm::setTransport()`), sehingga asersi tidak pernah
menyentuh jaringan: validasi URL/SSRF, penyusunan permintaan, header
Authorization hanya saat kunci ada, parsing respons, 401/429/JSON rusak,
respons kebesaran, penyamaran kunci, penyimpanan atomik + `.bak` + audit,
perilaku saat mati, dan `chatbot:llm` keluar 2 bila belum dikonfigurasi.

## 7. Yang perlu diputuskan sebelum fase 2

1. Peran LLM: hanya saat tidak ada jawaban (disarankan), atau juga menulis
   ulang jawaban FAQ?
2. Apakah boleh mengirim potongan hasil katalog sebagai konteks?
3. Batas biaya: hanya `max_calls_per_minute`, atau perlu kuota harian?

## 8. Rollback

Matikan `llm.enabled` (atau hapus `files/chatbot/llm.php`) — perilaku kembali
ke PR #1 tanpa menyentuh data lain. Tidak ada tabel, tidak ada migrasi.
