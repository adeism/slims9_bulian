---
title: Menghubungkan Chatbot ke Model Lokal atau API Kompatibel OpenAI
description: Mengatur dan menguji koneksi ke Ollama, LM Studio, llama.cpp, vLLM, atau API OpenAI-compatible — mati secara bawaan.
---

Halaman ini untuk administrator yang ingin chatbot memakai model bahasa. Semua
langkah di sini **opsional**: tanpa pengaturan ini chat tetap menjawab dari
intent, FAQ, dan katalog.

### Yang perlu disiapkan

Satu alamat API yang berbicara seperti OpenAI:

| Perangkat | URL dasar yang biasa dipakai |
|---|---|
| Ollama | `http://127.0.0.1:11434/v1` |
| LM Studio | `http://127.0.0.1:1234/v1` |
| llama.cpp server | `http://127.0.0.1:8080/v1` |
| vLLM / LocalAI | `http://127.0.0.1:8000/v1` |
| OpenAI / OpenRouter | `https://api.openai.com/v1` |

Nama model harus persis seperti yang ditawarkan server, misalnya
`llama3.1:8b` atau `qwen2.5:7b`. Tombol **Uji koneksi** menampilkan daftar
model yang tersedia bila server mendukung `GET /models`.

### Mengisi halaman

1. Buka **System → Chatbot LLM**.
2. Isi **URL dasar** dan **Model**. Untuk server di mesin yang sama, biarkan
   kunci API kosong.
3. Untuk API di internet, centang **Izinkan host di luar jaringan lokal** dan
   isi **Kunci API**. Biarkan **Verifikasi TLS** tetap tercentang.
4. Klik **Simpan koneksi**, lalu **Uji koneksi**.

Cara lain lewat terminal:

```bash
php index.php chatbot:llm          # apa yang tersimpan (kunci disamarkan)
php index.php chatbot:llm --test   # uji koneksi, tampilkan latensi + model
```

Kode keluar: `0` tersambung, `1` gagal, `2` belum dikonfigurasi.

### Keamanan yang sudah diatur

* Kunci API disimpan di `files/chatbot/llm.php` dengan izin `0600`, tidak
  pernah dikirim balik ke browser (hanya `••••abcd`), dan tidak pernah masuk
  log. Kolom kunci yang dibiarkan kosong mempertahankan kunci lama.
* Server di luar jaringan lokal ditolak sampai Anda mencentang **izinkan
  host remote**. Alamat metadata cloud (`169.254.169.254`) selalu ditolak.
* Pengalihan (redirect) tidak diikuti, balasan di atas 256 KB ditolak, dan
  setiap pesan ke model dipotong 4000 karakter.
* Setiap penyimpanan dicatat di `files/chatbot/audit.ndjson.php` (siapa,
  kapan, host, model) — tanpa kunci.

### Bila uji koneksi gagal

| Pesan | Arti | Tindakan |
|---|---|---|
| `CONNECTION_FAILED` | server tidak menerima koneksi | pastikan layanan berjalan dan port benar |
| `TIMEOUT` | melewati batas waktu | naikkan **Batas waktu**, cek beban server |
| `NOT_FOUND` | URL dasar salah | biasanya kurang `/v1` |
| `UNAUTHORISED` | kunci salah atau kosong | isi ulang kunci API |
| `RATE_LIMITED` | kuota penyedia habis | tunggu atau ganti model |
| `REMOTE_BLOCKED` | host di luar jaringan | centang izin remote bila disengaja |

### Kapan model ikut menjawab

Model hanya dipakai pada satu situasi: **chat tidak punya jawaban sendiri**.

* Perintah, pencarian, pinjaman, data anggota, dan semua transaksi tetap
  dijawab mesin deterministik; model tidak dipanggil.
* Pesan yang tidak dikenali, atau pencarian tebakan yang tidak menemukan apa
  pun dan tidak punya saran "mungkin maksud Anda", boleh dijawab model.
* Pertanyaan pengunjung sudah disaring aturan `question` sebelum mesin
  berjalan, dan teks jawaban model disaring aturan `answer` sebelum tampil.
* Yang dikirim ke model hanya **pertanyaan dan bahasa**. Tidak ada judul
  katalog, entri FAQ, atau data anggota.

Jawaban model dipotong 600 karakter. Bila gagal (waktu habis, kunci salah,
kuota habis, balasan rusak), pengunjung tetap menerima jawaban lama, kode
kegagalan masuk riwayat (`stage=llm-error`), dan **Penghentian otomatis** di
halaman ini menghitungnya. Setelah **3 kegagalan berturut-turut**, panggilan
dihentikan selama **5 menit** agar server model yang mati tidak memperlambat
setiap pertanyaan; tekan **Setel ulang breaker** setelah masalahnya beres.

Batas **Panggilan per menit** (bawaan 6) berlaku per sesi pengunjung.

Tombol **Tanya model langsung** mengirim satu pertanyaan dengan prompt yang
sama seperti chat, tanpa menyentuh data perpustakaan — cara cepat memeriksa
nada jawaban sebelum diaktifkan.
