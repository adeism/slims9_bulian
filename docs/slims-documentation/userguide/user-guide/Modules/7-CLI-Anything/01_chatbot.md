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

### Pencarian yang memaafkan salah ketik

Tidak semua orang mengetik judul dengan benar: *"sejarh"*, *"laskr pelangi"*,
*"metodelogi"*. Chatbot tidak langsung menjawab "tidak ada"; ia mencari dalam
tiga tahap, dan tahap berikutnya hanya dijalankan bila tahap sebelumnya kosong.

| Tahap | Yang dilakukan | Contoh |
|---|---|---|
| 1. persis | semua kata dicocokkan ke judul, ISBN/ISSN, nomor panggil, pengarang, subjek, dan penerbit | `sejarah indonesia` |
| 2. kata dipendekkan | dua huruf terakhir dibuang, sehingga salah ketik di ujung kata tetap menemukan judulnya | `sejarh` → `seja` |
| 3. "mungkin maksud Anda" | judul terdekat dihitung dari kemiripan teks | `laskr pelangi` → *Laskar Pelangi* (86%) |

Urutan hasil juga diperhitungkan, bukan sekadar "yang terbaru di atas":

* judul yang persis sama lebih tinggi daripada judul yang hanya memuat kata itu;
* ISBN yang diketik (atau hasil pindai barcode) langsung menuju judulnya;
* nomor panggil, pengarang, subjek, lalu penerbit menyusul di bawahnya;
* bila nilainya sama, **jumlah peminjaman** menentukan — judul yang sering
  dipinjam naik.

Karena itu *"buku populer"* atau *"terlaris"* menampilkan judul yang paling
sering dipinjam, dan tiap baris menyebutkan `41x dipinjam` supaya urutannya
terlihat, bukan sekadar dijanjikan. *"Buku terbaru"* mengurutkan dari yang
terakhir diperbarui. Judul yang disembunyikan dari OPAC (`opac_hide`) tidak
muncul untuk tamu dan anggota, tetapi tetap bisa ditemukan staf.

Contoh percakapan:

```
Anda: cari sejarh
Chatbot: Ditemukan 1 dari 1 judul untuk "sejarh":
  [i] Tidak ada yang persis cocok dengan "sejarh", jadi ini judul yang paling dekat.
  • Sejarah Indonesia Modern [2/3 Tersedia]  Budi Santoso · 2019 · 959.8 BUD s

Anda: buku populer
Chatbot: Judul yang paling sering dipinjam:
  • Sejarah Indonesia Modern [2/3 Tersedia]  … · 41x dipinjam
  • Sejarah Nusantara [0/1 Tersedia]  … · 7x dipinjam

Anda: cari laskar pelangii
Chatbot: Tidak ada yang persis cocok dengan "laskar pelangii". Mungkin maksud Anda salah satu ini?
  • Laskar Pelangi [86%]  Andrea Hirata · 2005
  [i] Kirim lagi judulnya, pakai kata yang lebih sedikit, atau sebut nama pengarangnya.
```


#### Bisa disesuaikan

Semua angka di atas bukan pajangan; Ubah lewat blok `search` di
`config/chatbot.php`. Cukup tulis kunci yang ingin diubah — sisanya memakai
nilai bawaan, dan blok `weights` digabung, bukan ditimpa seluruhnya.

```php
'search' => [
    'sort' => 'relevance',
    'typo' => true,             // tahap "kata dipendekkan"
    'suggestions' => 5,         // jumlah judul pada "mungkin maksud Anda"
    'min_similarity' => 0.55,   // di bawah ini dianggap tidak mirip
    'stopwords' => ['skripsi'], // kata yang diabaikan, tambahan
    'weights' => [
        'author' => 45,         // pengarang jadi lebih penting
    ],
],
```

Blok yang sama dibaca console dari `config/cli_anything.php`, sehingga aturan
pencarian chatbot dan terminal tidak berbeda diam-diam.

Bila katalog Anda sangat besar, Anda boleh menambahkan index FULLTEXT pada
`biblio.title`; chatbot akan memakainya secara otomatis (dan tetap memakai
pencarian biasa bila index tidak ada). Perintah di bawah **tidak** dijalankan
oleh plugin — ini keputusan Anda sebagai pengelola basis data:

```sql
ALTER TABLE biblio ADD FULLTEXT INDEX ft_biblio_title (title);
```

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

### Dipasang di SLiMS yang belum diubah

Chatbot tidak menuntut perubahan kode inti. Ia hanya menambah berkas di
`plugins/chatbot/` dan `config/`, lalu memakai API plugin bawaan SLiMS 9:
menu OPAC/System dan kait `custom_api_route` yang sudah dijalankan
`api/v1/routes.php`.

Artinya Anda bisa memasangnya di instalasi SLiMS 9 Bulian yang masih asli:

```bash
cp -r plugins/chatbot /var/www/slims/plugins/
cp config/chatbot.sample.php /var/www/slims/config/chatbot.php
cd /var/www/slims && php index.php plugin:list --type=all
```

Bila hanya folder chatbot yang disalin, plugin tetap punya lapisan data:
salinan layanan CLI-Anything disertakan di `Support/vendor/` dan hanya dipakai
kalau `plugins/Commands/Support/CliAnything.php` tidak ada. Penulisan transaksi
tetap tertutup sampai Anda menambahkan `config/cli_anything.php`:

```php
<?php
return ['allow_write' => true];
```

Persyaratannya sama dengan SLiMS sendiri: PHP 7.4 atau lebih baru, MySQL/
MariaDB dengan skema SLiMS 9, dan tabel `biblio`, `item`, `member`, `loan`,
`fines` yang selalu ada. Penulisan memakai kelas sirkulasi milik SLiMS, jadi
aturan pinjam, struk, dan catatan `system_log` sama seperti dari area admin.

### Manfaat dan risikonya

Sebelum memasang, ada baiknya tahu apa yang bertambah dan apa yang perlu
dijaga.

**Yang bertambah**

| Yang ditambahkan | Sifat |
|---|---|
| folder `plugins/chatbot/` | baru, tidak menimpa berkas apa pun |
| `config/chatbot.php` | baru, dari contoh yang disediakan |
| menu **Chatbot** di OPAC dan **Sistem → Chatbot** | bisa dimatikan lewat konfigurasi |
| rute `api/chatbot/v1/message` | **hanya** aktif bila webhook dinyalakan dan token diisi |
| baris di `system_log` | satu baris untuk setiap transaksi yang benar-benar ditulis |

Tidak ada tabel baru, tidak ada kolom baru, dan tidak ada berkas `admin/`,
`lib/`, atau `index.php` yang diubah. Plugin juga tidak pernah menjalankan
`ALTER TABLE`.

**Manfaat**

* Pertanyaan rutin ("apakah buku ini tersedia?", "kapan jatuh tempo saya?",
  "berapa denda saya?") terjawab tanpa antre di meja sirkulasi.
* Pencarian memaafkan salah ketik dan diurutkan relevansi, sehingga katalog
  lebih mudah ditelusuri; "buku populer" menjadi daftar bacaan yang siap
  dipakai.
* Peran ditentukan sesi, bukan isi pesan: anggota tidak mungkin melihat data
  anggota lain, dan tamu diarahkan untuk masuk.
* Transaksi tetap dua langkah dan tercatat di log audit, dengan pengenal staf
  atau anggota yang sebenarnya.

**Risiko dan pengamannya**

| Risiko | Pengamanan bawaan |
|---|---|
| Transaksi keliru pada data nyata | penulisan mati secara bawaan (`allow_admin_write`/`allow_write`), selalu didahului pratinjau, konfirmasi sekali pakai dan kedaluwarsa 180 detik, setiap commit diaudit |
| Data pribadi terbaca di layar atau aplikasi pesan | e-mail, telepon, dan alamat selalu disamarkan; tidak ada jalan membuka data aslinya dari chat |
| Webhook disalahgunakan | mati secara bawaan, wajib token, pengirim tak dikenal diperlakukan sebagai tamu, dibatasi per pengirim |
| Folder rencana transaksi diakses lewat URL | nama berkas berupa hash, diberi penjaga `<?php exit; ?>`, folder `0700` dengan `.htaccess` penolak |
| Pencarian lambat pada katalog sangat besar | satu halaman maksimal 100 baris (chat 5), saran dibatasi 300 judul; index FULLTEXT dipakai otomatis bila Anda menambahkannya |
| Belum diuji pada basis data nyata di repositori ini | pasang dengan penulisan mati, jalankan `chatbot:selftest`, lalu uji di salinan panggung sebelum membuka penulisan |

**Mencabut pemasangan**

```bash
php index.php plugin:deactivate <id>
rm -rf plugins/chatbot
rm config/chatbot.php
```

Tidak ada yang perlu dibalik di basis data. Yang tertinggal hanyalah riwayat
audit di `system_log` dan — bila webhook dipakai — folder `SB files/chatbot/`;
keduanya aman dihapus setelah dibaca.

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
| Buku ada tetapi tidak ditemukan | judul disembunyikan dari OPAC (`opac_hide`) | cari dari akun staf, atau periksa status sembunyinya |
| Hasil populer tampak tidak wajar | riwayat `loan` baru sebagian dimigrasi | wajar pada data uji; urutan mengikuti jumlah pinjaman yang tercatat |
| Webhook menjawab `404 CHATBOT_DISABLED` | `channel.enabled` mati atau `token` kosong | isi keduanya, lalu simpan ulang |
| Webhook menjawab `401 UNAUTHORIZED` | header token salah atau gateway tidak mengirimnya | samakan dengan `channel.token` |
| Balasan selalu "masuk ke area anggota" | nomor/chat id belum terdaftar | tambahkan ke `channel.members` atau `channel.staff` |
| Balasan `RATE_LIMITED` | terlalu banyak pesan per menit dari satu pengirim | naikkan `rate_limit_per_minute` bila perlu |

### Untuk pengembang

Rincian arsitektur, kontrak JSON, dan daftar berkas ada di
[plugins/chatbot/README.md](https://github.com/adeism/slims9_bulian/blob/master/plugins/chatbot/README.md)
dan di [Development Guide → Console → CLI-Anything](../../../development-guide/06-Console/05-CLI-Anything.md).
