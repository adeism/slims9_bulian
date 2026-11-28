---
title: Meninjau dan Memperbaiki Jawaban Chatbot
description: Tiga halaman staf — riwayat, review, aturan — untuk melihat apa yang ditanyakan, membetulkan jawaban yang salah, dan memblokir hal yang tidak boleh dibahas.
---

Halaman ini untuk pustakawan yang bertugas menjaga mutu jawaban chatbot. Tidak
ada kode yang perlu disunting: semua tindakan tersedia di area staf, di bawah
menu **System**.

### Riwayat: apa yang ditanyakan

**System → Chatbot history** menampilkan percakapan yang tercatat di
`files/chatbot/` (satu berkas per bulan, retensi 90 hari).

Yang bisa dilakukan:

* menyaring per rentang hari, permukaan (OPAC, admin, WhatsApp, Telegram),
  peran, hanya yang belum terjawab, hanya yang ditandai tidak membantu, dan
  pencarian teks;
* membuka satu percakapan untuk melihat intent, jawaban yang dipakai, dan
  latensinya;
* mengekspor CSV untuk rapat atau laporan;
* membaca angka mingguan: persen terjawab, jumlah belum terjawab, 👍/👎,
  latensi p50/p95, aturan guard yang menolak, dan daftar pertanyaan yang paling sering ditanya tetapi
  tidak pernah terjawab.

Yang **tidak** disimpan: nama anggota, nomor telepon, surel, alamat IP. Hanya
hash aktor, potongan pertanyaan (300 karakter), dan metadata jawaban.

### Review: membetulkan yang salah

**System → Chatbot review** berisi antrean 30 hari terakhir: pertanyaan yang
tidak terjawab dan jawaban yang ditandai tidak membantu.

| Tombol | Gunanya |
|---|---|
| Tambah kata kunci | orang mengetik "buka sabtu", entri jam buka belum mengenalinya |
| Entri baru | pertanyaan yang belum ada jawabannya; simpan sebagai draf dulu |
| Sembunyikan | entri sudah tidak relevan; isinya tidak dihapus |
| Blokir | pertanyaan yang memang tidak boleh dibahas |
| Di luar cakupan | jawab sopan dan arahkan ke meja layanan |
| Uji pertanyaan | lihat jawaban yang akan diterima pengunjung |

Sebelum menyimpan, gunakan **Pratinjau**: jawaban yang sama ditampilkan tanpa
mengubah apa pun. Setelah tersimpan, perubahan langsung dipakai — tidak perlu
menyalakan ulang server. Bila keliru, **Kembalikan versi sebelumnya** memulihkan
satu versi terakhir, dan setiap perubahan tercatat di audit dengan nama staf.

### Aturan: yang tidak boleh dibahas atau diucapkan

**System → Chatbot rules** memuat dua jenis aturan:

* **question** — dijalankan sebelum pencarian: pesan yang diblokir tidak
  pernah mencapai katalog atau layanan;
* **answer** — menyaring isi FAQ dan setiap jawaban sebelum tampil, termasuk
  judul buku yang datang dari katalog.

Satu aturan berisi pola (frasa, kata, atau regex pendek), kategori, aksi, dan
**alasan** yang wajib diisi. Pencocokan tidak mudah diakali: huruf besar/kecil,
tanda baca, spasi berlebih, karakter tak terlihat, dan angka pengganti huruf
("b3bas denda") tetap tertangkap.

Kolom **Memblokir** menunjukkan berapa dari pertanyaan FAQ perpustakaan yang
juga ikut diblokir aturan itu. Bila angkanya besar, aturan terlalu luas dan
akan menolak pengunjung biasa — persempit polanya.

Aturan bawaannya kosong, dan `guard.enabled` mati. Chat berperilaku seperti
sebelumnya sampai administrator menyalakannya.

### Angka, bukan kesan

* `php index.php chatbot:eval` — akurasi pada korpus 124 kalimat (Indonesia
  dan Inggris, tiga peran), toleransi salah ketik, paritas bahasa, kontrak
  tampilan. Berakhir dengan kode 1 bila di bawah target.
* `php index.php chatbot:eval --sweep` — tabel precision/recall ambang FAQ,
  untuk memastikan ambang 0.62 bukan tebakan.
* `php index.php chatbot:log --days=7 --unanswered` — daftar yang belum
  terjawab tanpa membuka browser.
* `CHATBOT_E2E=1 php index.php chatbot:e2e` — dialog baca-saja terhadap basis
  data sungguhan, untuk staging sebelum rilis.

### Ritme yang disarankan

1. **Harian** — buka riwayat, cek "belum terjawab" dan 👎.
2. **Mingguan** — tambahkan kata kunci untuk pertanyaan berulang, buat entri
   baru untuk topik yang hilang, blokir yang memang di luar layanan.
3. **Sebelum rilis** — `chatbot:eval` hijau, `chatbot:faq --strict` bersih, dan
   tidak ada aturan yang memblokir pertanyaan normal.
