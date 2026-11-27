---
title: Mengisi FAQ Chatbot
description: Satu halaman isi yang diisi pustakawan — jam buka, alamat, telepon, keanggotaan, denda, dan pertanyaan umum lain — supaya chatbot menjawab tanpa mengubah kode.
---

Halaman ini untuk **pustakawan atau staf yang mengisi FAQ**, bukan untuk
pengembang. Semua isi FAQ berada di satu berkas; tidak ada kode yang perlu
disunting dan tidak ada basis data yang perlu diubah.

### Berkas yang diisi

| Berkas | Peran |
|---|---|
| `plugins/chatbot/Support/faq.sample.php` | contoh bawaan, jangan diubah |
| `config/chatbot.faq.php` | **isi sungguhan** milik perpustakaan Anda |

```bash
cp plugins/chatbot/Support/faq.sample.php config/chatbot.faq.php
php index.php chatbot:faq
```

Perintah kedua menampilkan berkas mana yang dipakai, daftar pertanyaan, dan
kesalahan yang perlu dibetulkan (mis. `id` ganda atau `answer` kosong). Perintah
ini tidak menulis apa pun dan aman dijalankan di server produksi.

### Bentuk satu entri

```php
[
    'id' => 'hours',                       // unik, dipakai pada meta jawaban
    'question' => 'Jam buka perpustakaan', // judul pada daftar "sering ditanyakan"
    'question_en' => 'Opening hours',      // opsional
    'keywords' => ['jam buka', 'buka', 'tutup', 'opening hours'],
    'answer' => 'Senin–Jumat 08.00–16.00, Sabtu 09.00–12.00.',
    'answer_en' => 'Monday to Friday 08.00–16.00, Saturday 09.00–12.00.',
    'link' => '',                          // opsional, tampil sebagai tautan
],
```

### Saran penulisan

* **Dua sampai empat kalimat pendek.** Chat membalas sebagai teks biasa; tabel
  atau daftar panjang sulit dibaca di ponsel.
* **Kata kunci mengikuti bahasa pengguna.** Tulis apa yang benar-benar diketik
  orang: "jam buka", "buka", "tutup", "opening hours", "weekend". Frasa lebih
  kuat daripada satu kata.
* **Satu topik satu entri.** Denda terpisah dari keanggotaan, alamat terpisah
  dari telepon, agar jawaban tetap spesifik.
* **Jangan menaruh data pribadi.** FAQ dibaca semua pengunjung, termasuk tamu.
* **Sebut kebijakan, bukan janji.** "Maksimal 3 eksemplar untuk 7 hari" lebih
  jelas daripada "bisa pinjam banyak".

### Pertanyaan yang sebaiknya ada

| Topik | Contoh isi |
|---|---|
| Jam layanan | hari kerja, Sabtu, hari libur nasional |
| Alamat dan lokasi | jalan, patokan, parkir |
| Telepon/WhatsApp dan surel | nomor, jam dibalas |
| Keanggotaan | syarat, tempat, masa berlaku, biaya |
| Batas pinjam | jumlah eksemplar, lama pinjam, koleksi terbatas |
| Perpanjangan | berapa kali, syarat, cara |
| Denda | tarif per hari, cara bayar, tunggakan |
| Reservasi | cara memesan, masa pengambilan |
| Fasilitas | ruang baca, wifi, colokan, loker |
| Layanan referensi | jam pustakawan, bantuan sitasi |
| Kunjungan sekolah | syarat, tenggat pengajuan |
| Donasi buku | kriteria, alur pemeriksaan |
| Kartu hilang | prosedur, biaya pengganti |

### Mengubah perilaku jawaban

Semua pengaturan ada di `config/chatbot.php`:

```php
'faq' => [
    'enabled' => true,     // matikan bila FAQ belum siap
    'threshold' => 0.62,   // lebih kecil = lebih sering menjawab
    'topics' => 3,         // jumlah pertanyaan lain yang ditawarkan
    'file' => '',          // kosong = config/chatbot.faq.php
],
```

Bila jawaban terasa "melenceng" ke topik lain, naikkan `threshold` sedikit
(mis. `0.7`). Bila pertanyaan wajar tidak dijawab, turunkan ke `0.55` atau
tambahkan kata kuncinya.

Satu hal yang perlu diingat saat menulis kata kunci: **kata yang terlalu umum
tidak menolong**. `harga`, `biaya`, `info`, dan sejenisnya hanya dihitung bila
kata itu menjadi seluruh pertanyaan atau bagian dari frasa yang lebih panjang
(`biaya keanggotaan`). Itu sebabnya "berapa harga tiket bus" tidak lagi
dijawab dengan iuran anggota. Tulis frasa yang benar-benar diucapkan orang,
bukan satu kata kunci yang lebar.

FAQ **tidak** mengganggu perintah: pertanyaan seperti "pinjaman saya" atau
"cari buku sejarah" tetap ditangani oleh mesin katalog dan sirkulasi, bukan
oleh FAQ.

### Memperbaiki lewat halaman review

Pustakawan tidak harus menyunting berkas. **System → Chatbot review**
menampilkan pertanyaan yang belum terjawab dan jawaban yang ditandai tidak
membantu, lalu menyediakan tombol:

* **tambah kata kunci** pada entri yang sudah ada;
* **entri baru** (berstatus draf sampai diaktifkan);
* **sembunyikan** entri tanpa menghapus isinya;
* **pratinjau** untuk melihat jawaban sebelum disimpan;
* **kembalikan versi sebelumnya** bila perubahan ternyata salah.

Perubahan itu disimpan di `files/chatbot/faq.local.php` (lapisan tambahan),
bukan di `config/chatbot.faq.php`. Berkas asli tetap milik perpustakaan dan
tidak pernah ditulis oleh server web. Setiap perubahan tercatat dengan nama
staf yang melakukannya.

### Setelah mengubah isi

1. Jalankan `php index.php chatbot:faq --strict` — harus berakhir tanpa
   kesalahan, dan memberi tahu bila chat masih memakai contoh bawaan atau ada
   entri yang disembunyikan aturan jawaban.
2. Uji satu atau dua pertanyaan di OPAC (`index.php?p=chatbot`), termasuk
   pertanyaan yang sengaja salah ketik.
3. Bila memakai WhatsApp/Telegram, uji juga dari nomor yang terdaftar.

Tidak perlu menyalakan ulang server: berkas dibaca ulang saat berubah.
