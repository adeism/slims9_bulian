---
title: Pengenalan
description: Fitur pendamping yang memungkinkan asisten digital (chatbot, skrip, agen AI) membaca data perpustakaan dan — bila diizinkan — mencatat transaksi sirkulasi dengan pengamanan berlapis.
---

Halaman ini menjelaskan fitur **CLI-Anything** dari sudut pandang pustakawan.
Penjelasan teknis untuk pengembang ada pada
[Development Guide → Console → CLI-Anything](../../../development-guide/06-Console/05-CLI-Anything.md).

### Apa ini?

Selama ini pekerjaan perpustakaan dilakukan dengan mengklik menu di antarmuka web
SLiMS. **CLI-Anything** membuka pintu kedua: data dan transaksi yang sama dapat
diakses oleh **asisten digital** — chatbot WhatsApp/Telegram, skrip laporan
terjadwal, atau agen AI — melalui perintah teks.

Analogi sederhananya: SLiMS tetap "rak buku" yang sama, tetapi sekarang ada petugas
tambahan yang bisa ditanya lewat pesan singkat, bukan hanya lewat layar.

Pintu kedua ini **tidak mengubah** cara kerja SLiMS. Semua aturan perpustakaan
(masa pinjam, denda, reservasi, batas jumlah pinjaman) tetap dihitung oleh SLiMS
sendiri, persis seperti saat transaksi dilakukan di menu Sirkulasi.

### Yang bisa dilakukan tanpa mengubah pengaturan apa pun

Pada kondisi bawaan, fitur ini **hanya membaca**. Pekerjaan yang bisa dibantu:

* Menjawab pertanyaan ketersediaan: *"Apakah buku Sejarah Indonesia Modern ada
  yang tersedia?"* — lengkap dengan jumlah eksemplar dan status pinjam.
* Menjawab keterlambatan: *"Siapa saja yang terlambat mengembalikan minggu ini?"*
* Menjawab data anggota: *"Berapa pinjaman aktif anggota M0001 dan berapa dendanya?"*
* Menyiapkan angka untuk laporan harian/bulanan: jumlah koleksi, anggota, peminjaman
  hari ini, keterlambatan, dan total denda.
* Menjawab pertanyaan titik layanan: *"Buku dengan barkode B00012 sedang dipinjam
  siapa dan jatuh tempo kapan?"*

### Contoh percakapan

> **Pustakawan:** "Siti mau pinjam dua buku, ini barkodenya B00001 dan B00002. Bisa?"
>
> **Asisten:** "Bisa. M0001 (Siti) memenuhi syarat. Keduanya akan jatuh tempo
> 11 Desember 2026. Ini masih *rencana* — belum dicatat. Setujui untuk mencatat?"

Perhatikan kata **rencana**. Bila fitur tulis belum diaktifkan — atau pustakawan
belum menyetujui — asisten hanya menampilkan rencana dan **tidak ada data yang
tersimpan**.

### Yang sengaja tidak diizinkan

* **Menghapus** data bibliografi, eksemplar, atau anggota. Penghapusan tetap
  keputusan manusia melalui GUI.
* **Membuat atau menyunting katalog.** Katalogisasi, impor MARC, dan penyiangan
  tetap di menu Bibliografi.
* **Mengubah aturan** (masa pinjam, denda, jenis anggota) atau konfigurasi sistem.
* **Menembak basis data langsung.** Semua akses melewati logika SLiMS agar aturan
  dan riwayat pinjam tetap utuh.

### Bila fitur tulis diaktifkan

Operator dapat mengizinkan pencatatan peminjaman, pengembalian, dan perpanjangan.
Pengamanannya berlapis:

1. **Dry run lebih dulu.** Tanpa persetujuan eksplisit, tidak ada yang dicatat.
2. **Satu perintah, satu keputusan.** Bila satu dari beberapa item ditolak aturan
   pinjam, seluruh transaksi dibatalkan — tidak ada pencatatan setengah jalan.
3. **Cadangan otomatis.** Pencatatan lebih dari satu transaksi sekaligus selalu
   didahului salinan basis data di `files/backup/`.
4. **Jejak audit.** Setiap pencatatan tercatat di **System → System Log** dengan
   lokasi `CLI-Anything`, sehingga bisa dibedakan dari transaksi manual.
5. **Batas bawaan mati.** Selama operator tidak mengubah konfigurasi, semua
   perintah tulis berhenti dengan pesan *"Write operations are disabled"*.

### Perlindungan data anggota

Data pribadi anggota (surel, telepon, faks, alamat) **disamarkan** pada setiap
keluaran untuk asisten digital. Membukanya memerlukan pengaturan khusus
(`allow_pii_reveal`) yang sebaiknya dibiarkan mati. Data pemustaka adalah data
pribadi: jangan memasukkan keluaran yang tidak perlu ke dalam percakapan atau
layanan pihak ketiga.

### Daftar periksa sebelum mengaktifkan fitur tulis

1. Pastikan SLiMS diakses lewat HTTPS.
2. Minta admin membuat token khusus untuk asisten ini, dan simpan seperti kata sandi.
3. Arahkan ke akun staf khusus agar audit mudah dibaca.
4. Jalankan beberapa perintah **tanpa** persetujuan tulis, dan bandingkan hasilnya
   dengan tampilan GUI untuk memastikan aturan pinjam sesuai kebijakan perpustakaan.
5. Baru aktifkan izin tulis, dimulai dari uji coba satu item dan satu anggota.

### Bila ada masalah

Tanda-tanda yang perlu dilaporkan ke admin sistem:

* Pesan *"Write operations are disabled"* padahal seharusnya sudah diaktifkan.
* Pesan *"Invalid or missing CLI-Anything token"* — token berubah atau tidak terkirim.
* Asisten menjawab dengan halaman HTML, bukan data — alamat yang dipakai salah.
* Angka pada laporan berbeda dengan GUI — hentikan penggunaan tulis dan laporkan
  beserta waktu kejadian agar dapat ditelusuri pada System Log.

Keputusan layanan tetap milik pustakawan. Fitur ini mempercepat pekerjaan, bukan
menggantikan pertimbangan profesional anda.
