---
title: Chatbot Perpustakaan
description: Antarmuka percakapan untuk tamu, anggota, dan staf — di OPAC maupun di area admin — dengan pembatasan peran dan konfirmasi sebelum setiap transaksi.
---

Halaman ini membahas **Chatbot**, yaitu wajah percakapan dari fitur
[CLI-Anything](00_intro.md). Mesinnya sama: perintah yang dijalankan asisten
adalah perintah yang juga bisa Anda jalankan sendiri di terminal. Yang berbeda
hanya cara bertanyanya.

### Siapa yang bisa memakai

Peran ditentukan oleh **sesi SLiMS**, bukan oleh pesan. Tamu tidak bisa menjadi
anggota dengan mengetik sesuatu, dan anggota tidak bisa melihat data anggota lain
dengan menyebut nomornya.

| Kemampuan | Tamu (OPAC) | Anggota (sudah masuk) | Staf (admin) |
|---|---|---|---|
| Cari judul, lihat detail, cek ketersediaan eksemplar | ✅ | ✅ | ✅ |
| Cek status satu eksemplar lewat kode item | ✅ | ✅ | ✅ |
| Pinjaman dan denda sendiri | ❌ — diarahkan masuk | ✅ hanya akun sendiri | — |
| Pinjaman anggota lain | ❌ | ❌ | ✅ |
| Angka harian, diagnostik sistem | ❌ | ❌ | ✅ |
| Pinjam / kembali / perpanjang | ❌ | perpanjang milik sendiri, bila diizinkan | ✅ setelah konfirmasi |
| Menghapus data | ❌ | ❌ | ❌ (perintahnya tidak ada) |

Bila tamu menanyakan *"pinjaman saya"*, jawabannya bukan pesan galat, melainkan
tautan untuk masuk ke area anggota.

### Contoh percakapan

**Tamu**

> **Anda:** cari buku sejarah  
> **Asisten:** Ditemukan 5 dari 37 judul untuk "sejarah": …

> **Anda:** apakah buku #12 tersedia?  
> **Asisten:** Judul #12 — Sejarah Indonesia Modern · 2/3 eksemplar tersedia

**Anggota**

> **Anda:** pinjaman saya  
> **Asisten:** 2 pinjaman aktif: … (salah satunya bertanda *terlambat 4 hari*)

> **Anda:** denda saya  
> **Asisten:** Denda belum dibayar Rp6.000 · pinjaman aktif 2 · terlambat 1

**Staf**

> **Anda:** pinjaman terlambat  
> **Asisten:** 12 pinjaman terlambat: …

> **Anda:** ringkasan hari ini  
> **Asisten:** Pinjam hari ini 8 · Kembali hari ini 5 · Terlambat 12 · …

### Transaksi selalu dua langkah

Asisten tidak pernah langsung menulis. Permintaan transaksi dijawab dengan
**rencana**, lalu menunggu konfirmasi:

> **Anda:** pinjamkan B00001 ke anggota 123  
> **Asisten:** Rencana saja, belum ada yang ditulis  
> Anggota 123 · Rina Kartika  
> • Sejarah Indonesia Modern — B00001 · jatuh tempo 04/12/2026 · siap  
> Konfirmasi berlaku 180 detik dan hanya bisa dipakai sekali.  
> **[Konfirmasi] [Batal]**

Aturan yang perlu diingat:

1. **Rencana bukan transaksi.** Selama tombol Konfirmasi belum ditekan, tidak ada
   baris yang ditulis dan tidak ada denda yang terbentuk.
2. **Konfirmasi sekali pakai dan cepat kedaluwarsa.** Bila tombol dibiarkan terlalu
   lama, asisten akan menyusun ulang rencananya.
3. **Administrator tetap bisa mematikan penulisan.** Bila dimatikan, rencana tetap
   ditampilkan beserta alasannya — jadi Anda bisa melihat apa yang *akan* terjadi.
4. **Semua yang ditulis tercatat.** Setiap transaksi masuk ke log sistem dengan
   identitas staf (atau anggota, untuk perpanjangan mandiri), sehingga bisa
   ditelusuri di kemudian hari.

### Yang sengaja tidak dilakukan

* **Menghapus** data bibliografi, eksemplar, atau anggota.
* **Mengubah** aturan pinjam, denda, atau data katalog.
* **Menampilkan** email, telepon, atau alamat anggota — selalu tersamar, bahkan
  untuk staf.
* **Menjawab** pertanyaan di luar koleksi dan sirkulasi (mis. kebijakan anggaran).

### Mengaktifkan

1. Salin `config/chatbot.sample.php` menjadi `config/chatbot.php`.
2. Aktifkan plugin dari terminal:

   ```bash
   php index.php plugin:list --type=all
   php index.php plugin:activate <id>
   ```

3. Menu **Chatbot** muncul di OPAC (`index.php?p=chatbot`), dan **Sistem → Chatbot**
   muncul di area admin.
4. Uji dulu tanpa membuka penulisan:

   ```bash
   php index.php chatbot:selftest
   php index.php chatbot:ask "pinjaman terlambat"
   ```

5. Bila ingin mencoba transaksi, buka `allow_write` di `config/cli_anything.php`
   **dan** `allow_admin_write` di `config/chatbot.php`, lalu coba satu transaksi
   pada data uji.

### Lewat WhatsApp atau Telegram

Pertanyaan tidak harus datang dari halaman web. Mesin yang sama bisa menjawab
lewat webhook, sehingga pustakawan tetap bisa bertanya dari ponsel.

1. Isi blok `channel` di `config/chatbot.php`:

   ```php
   'channel' => [
       'enabled' => true,
       'token' => '<rangkaian acak yang panjang>',
       'verify_token' => '<kata sandi verifikasi Meta>',
       'members' => ['whatsapp:628123456789' => 'M001'],
       'staff'   => ['telegram:987654321' => 1],
   ],
   ```

2. Arahkan gateway (WhatsApp Cloud API, BotFather, atau gateway lokal) ke
   `https://<alamat-perpustakaan>/index.php?p=api/chatbot/v1/message` dengan
   header `X-Chatbot-Token: <token>`. Untuk WhatsApp, isi URL verifikasi dan
   `verify_token` dengan nilai yang sama.
3. Kirim satu pesan percobaan dari nomor yang **tidak** ada di daftar. Balasan
   yang benar adalah ajakan masuk area anggota, bukan data orang lain.

Yang perlu dipahami:

* **Daftar di `members` dan `staff` adalah satu-satunya penentu identitas.**
  Nomor atau chat id yang tidak terdaftar diperlakukan sebagai tamu. Sistem
  tidak pernah menebak akun dari isi pesan atau mencocokkan nomor telepon ke
  tabel anggota.
* **Konfirmasi dilakukan dengan membalas.** Karena tidak ada sesi peramban,
  rencana transaksi disimpan di server per pengirim dan balasan akhirnya
  berbunyi *"Balas YA untuk menulis, atau BATAL untuk membatalkan."* Rencana
  kedaluwarsa dalam 180 detik dan hanya bisa dipakai sekali.
* **Balasan berupa teks biasa**, dipotong pada 1.400 karakter agar muat di
  WhatsApp atau SMS. Data pribadi tetap disamarkan seperti di web.
* **Berkas sementara** disimpan di `SB files/chatbot/` (dapat diubah lewat
  `storage_dir`), dengan `.htaccess` yang menolak akses web dan nama berkas
  berupa hash, bukan nomor telepon.
* **Matikan bila tidak dipakai.** `enabled => false` menutup rute webhook
  sepenuhnya; rute bahkan tidak terdaftar.

### Bila ada yang tidak berjalan

| Gejala | Kemungkinan penyebab | Tindakan |
|---|---|---|
| Menu Chatbot tidak muncul | plugin belum aktif | `php index.php plugin:list --type=all`, lalu aktifkan |
| Muncul "asisten sedang dimatikan" | `enabled` / `opac` / `admin` bernilai salah | periksa `config/chatbot.php` |
| Setiap kirim pesan meminta muat ulang | cookie sesi diblokir atau halaman dari cache | muat ulang, periksa pengaturan sesi |
| Tombol Konfirmasi menjawab "kedaluwarsa" | melebihi 180 detik | ulangi pertanyaannya |
| Rencana tampil tetapi tidak bisa ditulis | gerbang tulis masih tertutup | buka secara sengaja, lalu cek `doctor` |
| Jawaban `UNEXPECTED_ERROR` | basis data atau tabel `loan_history` bermasalah | jalankan `php index.php clianything:doctor` |
| Webhook menjawab `404 CHATBOT_DISABLED` | `channel.enabled` mati atau `token` kosong | isi keduanya, lalu simpan ulang |
| Webhook menjawab `401 UNAUTHORIZED` | header token salah atau gateway tidak mengirimnya | samakan dengan `channel.token` |
| Balasan selalu "masuk ke area anggota" | nomor/chat id belum terdaftar | tambahkan ke `channel.members` atau `channel.staff` |
| Balasan `RATE_LIMITED` | terlalu banyak pesan per menit dari satu pengirim | naikkan `rate_limit_per_minute` bila perlu |

### Untuk pengembang

Rincian arsitektur, kontrak JSON, dan daftar berkas ada di
[plugins/chatbot/README.md](https://github.com/adeism/slims9_bulian/blob/master/plugins/chatbot/README.md)
dan di [Development Guide → Console → CLI-Anything](../../../development-guide/06-Console/05-CLI-Anything.md).
