# Laporan Perubahan: Port SLiMS 9 Bulian ke Rust

**Tanggal:** 22 November 2026

**Branch:** `arena/41e4c998-slims9-bulian`

**Basis:** `1d892599c131db3ae86d3f5376f245af3e87d4c7` (`master`)

## 1. Ringkasan

Pekerjaan ini memulai penulisan ulang SLiMS 9 Bulian ke Rust secara bertahap,
dengan mempertahankan aplikasi PHP asli. Implementasi awal OPAC dan 15 rute
REST API v1 telah dibuat; area anggota sedang dikembangkan sebagai fitur
**eksperimental dan opt-in**. Seluruh sistem SLiMS **belum** selesai dipindahkan
ke Rust dan hasil saat ini **belum layak untuk cutover atau produksi**.

Pendekatannya mengikuti alur kerja [rust-anything](https://github.com/erkamyaman/rust-anything):
memetakan perilaku PHP, membuat implementasi Rust, kemudian menguji respons
terhadap fixture dan melakukan iterasi. Fixture yang ada disusun dari pembacaan
kode PHP dan data contoh, **bukan** hasil rekaman dari instance PHP yang sedang
berjalan. Karena itu, hasil gate belum membuktikan kesetaraan penuh dengan PHP.

## 2. Perubahan yang sudah dilakukan

### 2.1 Fondasi Rust dan OPAC

- Menambahkan crate Rust di `rust/`, termasuk server HTTP, routing, JSON,
  template HTML, model domain, pembacaan data seed, dan antarmuka `Store`.
- Mengimplementasikan OPAC: beranda, pencarian dan filter, detail bibliografi,
  URL lama `index.php`, aset statis, dan pemeriksaan kesehatan `/healthz`.
- Membuat UI baru di `rust/static/opac.css`; tampilannya bukan salinan identik
  template PHP. Endpoint thumbnail mengirim gambar asli, belum mengubah ukuran.
- Menyediakan dua backend: **memory** untuk demo/pengujian yang mengambil data
  awal dari `install/*.sql`, dan klien **protokol MySQL** yang menjalankan query
  baca terhadap skema SLiMS. Penulisan anggota nonaktif secara default dan
  dibatasi untuk database uji lokal melalui opsi terpisah.
- Pencarian Rust menggunakan `LIKE`, bukan pencarian FULLTEXT PHP; hasil dan
  pengurutan dapat berbeda pada data atau konfigurasi tertentu.

### 2.2 REST API v1

- Mengimplementasikan 15 rute API untuk beranda, bibliografi, subjek, anggota
  teratas, item, serta ringkasan/tanggal peminjaman.
- Menangani bentuk JSON, nilai hitungan berbentuk string, pembentukan URL gambar,
  dan cache dalam proses sesuai kebutuhan implementasi awal.
- Menambahkan penanganan daftar ID kosong dan urutan tie yang deterministis.
  Beberapa perilaku sengaja berbeda dari PHP dan dijelaskan di
  [`rust/DECISIONS.md`](rust/DECISIONS.md).

### 2.3 Area anggota — sebagian dan eksperimental

Aktif hanya dengan fitur build `member-crypt` dan `SLIMS_MEMBER_EXPERIMENTAL=1`;
build default masih menampilkan halaman anggota informasional.

- Login anggota bcrypt, sesi dengan ID dan token CSRF acak, rotasi sesi saat
  login, logout melalui POST, serta cookie `HttpOnly`, `SameSite=Lax`, dan
  `Secure` secara default.
- Tampilan pinjaman aktif, riwayat pinjaman, dan **My Account** hanya-baca
  setelah autentikasi. Data dinamis di-escape dan respons bersifat `no-store`.
- Validasi ulang sesi terhadap fingerprint hash password anggota. Sesi dicabut
  ketika hash berubah atau data kredensial tidak ditemukan; kegagalan backend
  menghasilkan respons 503 tanpa memberikan akses.
- Pembatasan login lokal per proses: 8 percobaan per ID dan 200 secara global
  dalam 10 menit, dengan maksimal 4 pemeriksaan bersamaan. Ini belum merupakan
  pembatasan terdistribusi atau per alamat IP.
- Penyimpanan sesi memory secara default. Opsi `member-sqlite` menyediakan
  penyimpanan sesi lokal pada satu host, dengan transaksi untuk rotasi/logout
  dan migrasi skema yang mencabut sesi login lama tanpa fingerprint.
- Daftar bookmark SLiMS per anggota dengan 20 baris per halaman. Tambah/hapus
  tersedia hanya jika opsi penulisan uji diaktifkan.
- Keranjang judul per sesi anggota (tambahan setelah laporan awal): tambah, hapus, kosongkan, batas tetap 20,
  dan perlindungan CSRF. SQLite sesi menyimpan keranjang hingga sesi habis atau
  logout; mode memory hilang saat proses berhenti. Keranjang bukan bookmark
  maupun reservasi dan belum setara dengan alur PHP.
- Prototipe reservasi **hanya untuk backend memory** dan hanya jika diaktifkan
  terpisah. Pengecekan duplikasi dan batas dalam satu proses dilakukan secara
  atomik. Data demo hilang saat proses dimulai ulang dan **tidak** menulis
  reservasi ke database SLiMS.

- Prototipe penulisan uji: perubahan password dan migrasi MD5 ke bcrypt dengan
  compare-and-set, tambah/hapus bookmark, serta reservasi judul tunggal. Semua
  nonaktif secara default; MySQL dibatasi ke `127.0.0.1` dan nama database
  `slims_test*`, dan reservasi memerlukan opsi terpisah. Gunakan hanya database
  sementara. Guard nama bukan jaminan bahwa data aman untuk diubah.

Catatan keamanan: autentikasi area anggota belum mencakup LDAP, CAPTCHA, audit,
last-login, kebijakan password yang dapat dikonfigurasi, atau penegakan status
keanggotaan. Reservasi uji tidak aman bila penulis PHP beroperasi bersamaan.
Validasi ulang hash masih dapat berpacu dengan perubahan yang terjadi bersamaan;
status pending/kedaluwarsa tidak mencabut sesi. SQLite sesi hanya untuk sistem
file lokal satu host; data anggota dan token CSRF di dalamnya tidak dienkripsi.

### 2.4 Peralatan, dokumentasi, dan pengujian

- Menambahkan fixture perilaku di `rust/fixtures/behavior/cases.json`, tes Rust,
  alat replay `tools/gate.py`, dan alat perekam API `tools/record.py` untuk
  dipakai nanti terhadap PHP yang berjalan.
- Menambahkan `tools/fake_mysql.py`: test double protokol MySQL yang menerjemahkan
  query ke SQLite, serta `tools/mysql_roundtrip.sh` untuk uji round-trip dan gate.
- Menambahkan panduan dan catatan keputusan di [`docs/rust-port.md`](docs/rust-port.md),
  [`rust/README.md`](rust/README.md), [`rust/MAP.md`](rust/MAP.md),
  [`rust/HANDOFF.md`](rust/HANDOFF.md), dan [`rust/DECISIONS.md`](rust/DECISIONS.md).
  Skrip `rust/cutover.sh` tersedia sebagai alat bantu; **cutover belum dilakukan**.
- Tidak menambahkan dependency crate eksternal. Fitur anggota menggunakan
  `libcrypt` sistem Linux; opsi sesi SQLite juga membutuhkan `libsqlite3.so.0`.
  Test double MySQL membutuhkan dependency Python `mysql-mimic` dan `sqlglot`.

## 3. Hasil pengujian terakhir

Dijalankan pada 22 November 2026 di lingkungan kerja saat ini:

| Pemeriksaan | Hasil |
| --- | --- |
| `cargo test --offline` | 44 unit test dan 1 behavior test lulus; 1 tes integrasi MySQL diabaikan secara default |
| `cargo test --offline --features member-crypt` | 52 unit test, 1 behavior test, dan 9 tes alur anggota lulus; 2 tes MySQL opt-in diabaikan |
| `cargo test --offline --features member-sqlite` | 61 unit test, 1 behavior test, dan 11 tes alur anggota lulus; 2 tes MySQL opt-in diabaikan |
| Dua tes `member_mysql` yang dijalankan eksplisit dengan sesi SQLite dan test double | 2/2 lulus |
| Gate pada backend memory | 23/23 kasus yang berlaku lulus; 1 kasus khusus MySQL dilewati |
| `tools/mysql_roundtrip.sh` | 23/23 kasus yang berlaku lulus melalui protokol MySQL ke test double; 1 kasus khusus memory dilewati |

Fixture berisi 24 kasus total. Test double menggunakan SQLite di belakang
protokol MySQL; ini berguna untuk menguji protokol dan query, tetapi **bukan
server MySQL/MariaDB asli**. Pengujian tambahan terpisah terhadap MySQL 5.7.29
sungguhan menggunakan database sementara dan skema installer juga lulus; ini
bukan cakupan semua versi MySQL/MariaDB. Belum ada pembandingan respons dengan
instance PHP SLiMS yang hidup. Pengujian member mencakup CSRF, rotasi dan
pencabutan sesi, migrasi SQLite, batas login, isolasi anggota, pagination
bookmark, perubahan password/MD5 dan reservasi uji, bukan sertifikasi keamanan produksi.

## 4. Yang belum dilakukan / rencana berikutnya

Urutan di bawah merupakan rencana kerja, bukan janji tanggal penyelesaian.

1. **Validasi terhadap aplikasi asli:** siapkan lingkungan uji PHP + MySQL/
   MariaDB dengan data yang sama; rekam dan bandingkan API, OPAC, login anggota,
   profil, dan riwayat. Perbaiki perbedaan, termasuk perilaku FULLTEXT, timezone,
   collation, dan pengaturan SQL. Perlu diperluas juga pengujian regresi dan
   keamanan pada data non-demo.
2. **Selesaikan area anggota:** dukung alur autentikasi yang relevan pada PHP,
   termasuk konfigurasi auth/CAPTCHA, mengeraskan dan memvalidasi jalur password,
   migrasi MD5 dan bookmark untuk produksi, integrasi basket dengan reservasi
   PHP, serta pencatatan last-login/audit.
   Rancang sesi dan pencabutan yang aman untuk deployment multi-host, serta
   pembatasan login terdistribusi dan perlindungan terhadap denial of service.
3. **Reservasi nyata dan jalur tulis:** rancang konsistensi, audit, dan mekanisme
   pemulihan yang aman saat PHP dan Rust menulis bersamaan. Tabel `reserve`
   pada skema bawaan menggunakan MyISAM; transaksi Rust saja tidak cukup untuk
   menjamin batas atau mencegah duplikasi lintas penulis. Prototipe penulisan
   reservasi MySQL hanya untuk uji dengan penulis yang bekerja sama.
4. **Sirkulasi:** peminjaman, pengembalian, perpanjangan, denda, aturan pinjam,
   transaksi/konsistensi, dan audit terhadap database uji yang nyata.
5. **Administrasi dan modul lain:** katalogisasi dan item, otoritas, impor/
   ekspor, keanggotaan admin, laporan, stock take, pengaturan, OAI-PMH,
   plugin/hook, i18n, serta installer/upgrade yang masih berada di PHP.
6. **Hardening dan cutover:** TLS untuk akses database dan HTTP, prepared
   statements atau strategi query yang ditinjau, batas koneksi/thread dan pool,
   observability, backup, uji beban, audit keamanan, dan rencana rollback.
   Verifikasi paritas serta persetujuan operasional diperlukan sebelum cutover.

## 5. Cara menjalankan pemeriksaan

Dari root repository, dengan Rust/Cargo dan library sistem yang diperlukan:

```bash
cargo test --offline --manifest-path rust/Cargo.toml
cargo test --offline --manifest-path rust/Cargo.toml --features member-crypt
cargo test --offline --manifest-path rust/Cargo.toml --features member-sqlite
cargo build --offline --manifest-path rust/Cargo.toml
tools/mysql_roundtrip.sh
```

Untuk konfigurasi demo dan pengujian integrasi MySQL opt-in, lihat
[`rust/README.md`](rust/README.md) dan [`rust/HANDOFF.md`](rust/HANDOFF.md).
Jangan gunakan password contoh atau mode eksperimental dengan data anggota nyata.

### Pembaruan pengujian keranjang

`cargo test --offline` dan pengujian dengan fitur `member-crypt` serta
`member-sqlite` lulus. Fitur SQLite diuji termasuk migrasi sesi dan perubahan
keranjang serentak pada beberapa instance. Dua tes integrasi dengan protokol
MySQL dan penyimpanan sesi SQLite juga lulus terhadap `tools/fake_mysql.py`;
pengujian tersebut menggunakan double, bukan server MySQL/MariaDB nyata atau
pembandingan PHP.

### Pembaruan pengujian MySQL dan area anggota

Rangkaian default, `member-crypt`, dan `member-sqlite` lulus; fitur SQLite
mencakup 64 tes pustaka, 1 tes behavior, dan 13 tes alur anggota. Pengujian
SQL tambahan menggunakan database MySQL 5.7.29 sementara lulus, termasuk
pagination, isolasi, concurrent writes, dan simulasi balasan write hilang.
Alur HTTP login, bookmark, reservasi uji, perubahan password dan login ulang juga
lulus dengan sesi SQLite. Behavior gate OPAC lulus 23/23 pada server MySQL ini
(satu tes khusus backend memory dilewati); query collection type diperbaiki agar
sesuai mode `ONLY_FULL_GROUP_BY`. Semua hasil ini tidak membuktikan paritas PHP
atau keamanan produksi. Petunjuk dan keterbatasan ada di `rust/README.md`.

### Pengamanan status anggota pada reservasi

Reservasi demo dan prototipe MySQL kini menolak anggota pending, kedaluwarsa,
atau tanpa tanggal kedaluwarsa yang valid. Hari kedaluwarsa masih diizinkan,
berdasarkan tanggal UTC. Pemeriksaan MySQL dilakukan saat tabel anggota terkunci,
bukan mengandalkan status saat login. Perubahan status setelah login langsung
diperiksa sebelum insert. Login, informasi akun dan riwayat tidak diblokir.
Ini kebijakan prototipe yang lebih ketat, bukan klaim paritas konfigurasi PHP.

Validasi tambahan: tes kebijakan tanggal (termasuk tanggal kalender tidak valid),
reservasi memory, dan perubahan status setelah login pada MySQL 5.7.29. Seluruh
rangkaian SQLite kini lulus 66 tes pustaka, 1 behavior, dan 13 alur anggota;
tes MySQL destruktif dijalankan terpisah terhadap database sementara.

### Reservasi per judul dari keranjang

Setiap baris keranjang kini menampilkan tombol reservasi jika mode demo atau
reservasi database uji diaktifkan. Tombol memakai endpoint POST dan CSRF serta
pemeriksaan kebijakan yang sudah ada. Isi keranjang tetap dipertahankan setelah
berhasil atau gagal; tidak ada submit massal, email, atau plugin PHP.

Regresi default, `member-crypt`, dan `member-sqlite` lulus. Suite SQLite terbaru
mencakup 66 tes pustaka, 1 behavior, dan 14 alur anggota. Tes destruktif terpisah
pada MySQL 5.7.29 sementara dengan sesi SQLite juga lulus. Mode ini tetap untuk
uji saja, bukan reservasi produksi atau jaminan penulisan bersamaan dengan PHP.

### Informasi keterlambatan pinjaman aktif

Daftar pinjaman aktif menampilkan penanda keterlambatan dan jumlah hari kalender
berdasarkan UTC. Jatuh tempo hari ini belum terlambat. Nilai tanggal kosong atau
tidak valid tidak diklasifikasikan; teks tetap di-escape. Riwayat pengembalian
tidak diberi penanda ini. Belum mencakup denda, hari libur atau zona waktu PHP.

Pengujian mencakup batas hari, tahun kabisat, tanggal tidak valid, escaping,
akses anggota dan tampilan riwayat. Suite SQLite terbaru: 67 tes pustaka,
1 behavior dan 15 alur anggota lulus. Tidak ada perubahan penulisan database.

### Unduhan CSV per halaman pinjaman

Halaman pinjaman aktif dan riwayat kini menyediakan unduhan CSV maksimal 20
baris sesuai halaman, bukan seluruh laporan/PDF PHP. Unduhan memakai pemeriksaan
sesi, kredensial dan isolasi anggota yang sama; sesi anonim atau logout tidak
mendapatkan data. Header no-store dan nosniff ditambahkan. Formula spreadsheet
serta nilai berawalan kontrol diberi prefiks apostrof, sehingga ini bukan backup
lossless. Nilai NULL menjadi kolom kosong. Pagination dapat bergeser bila data
berubah saat ekspor, seperti tampilan HTML.

Seluruh suite default/crypt/SQLite lulus; SQLite terbaru mencakup 68 tes pustaka,
1 behavior, 16 alur anggota. Tes MySQL 5.7.29 sementara dengan sesi SQLite juga
lulus, termasuk ekspor halaman 20+2 pinjaman sintetis dan isolasi anggota.

### Penolakan permintaan anggota yang ambigu

Area anggota menolak parameter `sec`, `page`, `format` ganda serta lebih dari
satu header Content-Type pada POST. Cookie sesi dibaca dari seluruh header
Cookie, tetapi cookie sesi ganda tidak diterima walaupun nilainya sama.
Permintaan yang ditolak tidak membatalkan sesi valid atau mengubah keranjang.
Respons anggota juga memakai `Referrer-Policy: no-referrer` dan `nosniff`.

Tes mencakup header terpisah, perbedaan kapitalisasi, cookie/parameter ganda,
tidak adanya mutasi saat ditolak, dan penolakan CSV setelah kredensial berubah.
Suite SQLite terbaru lulus 68 tes pustaka, 1 behavior dan 17 alur anggota.
Ini pengamanan tambahan, bukan audit menyeluruh keamanan HTTP atau produksi.

### Keluar dari semua sesi Rust

Halaman akun kini menyediakan POST ber-CSRF untuk menghapus seluruh sesi anggota
pada penyimpanan sesi Rust yang sama, termasuk sesi pemanggil dan keranjangnya.
Identitas tidak diambil dari formulir. Anggota lain dan sesi anonim tidak ikut
terhapus. Memory berlaku satu proses; SQLite berlaku bagi worker dengan file
sama. Sesi PHP, penyimpanan lain, permintaan yang sudah diotorisasi dan login
yang selesai sesudah penghapusan tidak dibatalkan. Fitur ini bukan pengganti
perubahan password yang bocor dan tidak membutuhkan opsi penulisan database uji.

Tes default/crypt/SQLite lulus. SQLite terbaru: 70 tes pustaka, 1 behavior,
19 alur anggota, termasuk lintas worker, penolakan CSRF/anonim/kedaluwarsa,
isolasi anggota, pencabutan akses CSV dan rollback kegagalan penghapusan.

### Aksi anggota dari detail katalog

Detail katalog menyediakan tautan ke konfirmasi aksi anggota ketika mode
anggota aktif, sehingga ID judul tidak perlu diketik. Halaman terautentikasi
menawarkan tambah keranjang dan, jika opsi uji/demo aktif, bookmark/reservasi.
GET/HEAD tidak mengubah data; pengiriman tetap POST ber-CSRF. Judul tersembunyi
atau tidak ada ditolak dan judul di-escape. Katalog publik tidak memuat token
sesi. Pengguna anonim perlu login lalu membuka ulang tautan aksi dari katalog;
belum ada pengalihan otomatis ke judul setelah login.

Regresi default/crypt/SQLite lulus; SQLite terbaru mencakup 70 tes pustaka,
1 behavior dan 20 alur anggota. Tes mencakup visibilitas, escaping, opsi fitur,
GET/HEAD tanpa mutasi, serta POST tambah keranjang. Tetap eksperimental.

### Kembali ke konfirmasi judul setelah login

Batasan navigasi sebelumnya diperbaiki: login dari aksi judul kini membawa ID
numerik tervalidasi dan kembali ke konfirmasi judul sesudah berhasil. Salah
password mempertahankan ID itu. URL bebas, karakter kontrol, angka nol/negatif,
overflow dan nilai ganda tidak diterima sebagai tujuan. Login tidak langsung
menambah keranjang/bookmark atau mereservasi; visibilitas judul diperiksa ulang
pada halaman konfirmasi. Tidak ada perubahan skema sesi.

Suite default/crypt/SQLite lulus; SQLite terbaru berisi 70 tes pustaka,
1 behavior dan 21 alur anggota. Tes mencakup retry password, rotasi sesi,
penolakan tujuan berbahaya dan tidak adanya mutasi setelah login.

### Daftar reservasi tersimpan tanpa penulisan

Menu Stored Reservations menampilkan maksimal 100 reservasi tersimpan milik
anggota yang login, berdasarkan ID terbaru. Tidak memerlukan mode penulisan
uji. Pembacaan memakai identitas sesi; pencocokan ID bersifat byte-exact.
Referensi katalog yang hilang tidak ditampilkan. Memory memisahkan data fixture
tersimpan dari mutasi reservasi demo. Belum ada pembatalan atau interpretasi
status pemenuhan reservasi.

Toolchain Rust dipulihkan setelah lingkungan sandbox berubah. Suite default,
crypt dan SQLite dijalankan ulang; SQLite terbaru mencakup 70 tes pustaka,
1 behavior dan 22 alur anggota. Tes mencakup batas 100, urutan, isolasi anggota,
escaping dan GET tanpa mutasi. Server MySQL sementara dari lingkungan sebelumnya
tidak tersedia, sehingga integrasi MySQL tidak dijalankan ulang pada perubahan
ini; jalur baca memakai query reservasi yang sebelumnya sudah diuji.

### Target akhir: Rust menggantikan area anggota

Pengguna memilih Rust sebagai pengganti area anggota dengan administrasi tetap
PHP. Ditambahkan gate opt-in PHP `SLIMS_MEMBER_OWNER=rust` untuk menolak jalur
anggota lama, unduhan pinjaman anggota, autentikasi anggota Native dan pembuatan
reservasi melalui sirkulasi PHP. Default tetap PHP; nilai konfigurasi tak dikenal
menolak jalur yang dijaga. Tidak ada redirect/replay POST atau pengaktifan
penulisan produksi Rust.

Runbook `docs/member-cutover.md` mencakup inventaris penulis, drain request,
verifikasi proxy/FPM, validasi database, invalidasi sesi lama dan rollback.
Gate bukan bukti seluruh penulis terkendali: administrasi, plugin, proses aktif
dan penulis eksternal tetap perlu ditinjau. Lima tes gate/lint lulus menggunakan
PHP WASM CLI. Belum ada uji aplikasi SLiMS/FPM/proxy penuh. Area anggota produksi
belum selesai dan belum dilakukan cutover.

### Menutup bypass autentikasi saat cutover

Gate kepemilikan kini juga menjaga dispatch anggota Validator (termasuk provider
kustom yang meng-override member), Contract, pembuatan sesi anggota, LDAP dan
kelas member_logon lama. Pengecekan utility tidak mengakui sesi PHP anggota lama
saat Rust memiliki area anggota; data sesi tidak dihapus. Login admin tetap
tersedia. Provider kustom yang dipanggil di luar jalur ini, plugin, constructor
atau SQL langsung tetap memerlukan inventaris terpisah.

Delapan tes gate/lint lulus melalui PHP WASM CLI: penolakan jalur anggota,
provider/session generation, pengabaian sesi lama, serta dispatch admin yang
tetap berhasil. Belum ada uji LDAP nyata, FPM/proxy atau database penuh.
Tidak ada perubahan Rust atau pengaktifan penulisan produksi pada langkah ini.

### Pengujian respons HTTP cutover

Ditambahkan `tools/test_member_owner_http.mjs`: 16 kasus respons PHP terisolasi
lulus untuk GET/POST/HEAD, status 503, header plain-text/no-store/nosniff/
no-referrer, tanpa redirect, serta penolakan override konfigurasi dari request.
Delapan tes CLI gate/lint juga tetap lulus. Helper publik Native::setMemberData
kini dijaga sebelum membaca bookmark atau menulis last-login agar pemanggilan
langsung tidak melewati gate. Petunjuk tooling uji tersedia di runbook cutover.

Pengujian ini memakai PHP WASM, bukan deployment FPM/proxy atau aplikasi SLiMS
berdatabase penuh. Tidak mengubah Rust maupun membuka penulisan produksi.

### Hardening transport HTTP Rust

Pembacaan request/header kini dibatasi sejak proses baca (64 KiB), bukan baru
dicek setelah alokasi tak terbatas. Parser menolak header tidak lengkap atau
malformed, framing Content-Length ganda/tidak valid, Transfer-Encoding dan
Expect yang belum didukung. Koneksi bermasalah ditutup sebelum handler dipanggil;
sisa data tidak diproses sebagai request berikutnya. HTTP/1.0 ditutup setelah
respons. Proxy harus mengirim body ber-Content-Length yang tidak ambigu.

Serializer respons juga diperbaiki agar tidak menambahkan Referrer-Policy bawaan
yang lebih lemah di samping no-referrer milik area anggota. Default/crypt/SQLite
lulus; SQLite terbaru: 76 tes pustaka, 1 behavior dan 22 alur anggota. Gate HTTP
memory nyata lulus 23/23 kasus yang berlaku, satu kasus MySQL dilewati. Integrasi
MySQL tidak diulang pada langkah ini. Belum merupakan audit HTTP atau kesiapan
produksi: batas thread/koneksi dan deadline absolut masih perlu ditangani.

### Pembatasan koneksi dan deadline baca HTTP

Server kini membatasi 128 koneksi worker aktif per listener serta 100 request
per koneksi. Koneksi berlebih langsung ditutup tanpa membuat thread baru.
Slot dipulihkan saat EOF, error, kegagalan spawn atau unwinding. Pembacaan satu
request (idle keep-alive, header, body) memiliki deadline absolut 15 detik;
pengiriman byte sedikit demi sedikit tidak memperpanjang budget.

Tes default/crypt/SQLite lulus, dengan SQLite terbaru 81 tes pustaka,
1 behavior dan 22 alur anggota. Gate HTTP memory lulus 23/23 kasus yang berlaku,
satu kasus MySQL dilewati. Tes mencakup pemulihan slot, unwind, socket berlebih,
batas pipeline dan deadline tanpa pemanggilan handler. Ini bukan limiter
terdistribusi atau jaminan tahan DoS; handler/database tidak dibatalkan dan
write response belum memiliki deadline absolut. MySQL tidak diuji ulang.

### Deadline absolut penulisan respons

Penulisan seluruh respons kini memiliki budget tetap 15 detik setelah handler
selesai, dimulai sebelum serialisasi. Write parsial tidak memperbarui budget;
timeout menutup koneksi tanpa mengulang aksi. Tes TCP dengan klien yang tidak
membaca data memverifikasi deadline dan pemulihan slot worker.

Default/crypt/SQLite lulus; SQLite terbaru: 83 tes pustaka, 1 behavior,
22 alur anggota. Gate HTTP memory lulus 23/23 kasus yang berlaku, satu kasus
MySQL dilewati. Tidak ada pengujian ulang MySQL pada langkah ini. Handler/DB
belum dibatasi oleh deadline ini. Perubahan database dapat sudah tersimpan saat
respons gagal terkirim; klien perlu memeriksa status sebelum mengulangi aksi.

### Validasi ulang MySQL nyata dan HTTP anggota terbaru

Database MySQL 5.7.29 sementara dipulihkan dengan skema installer. Tes baca awal
menemukan fixture belum memiliki bookmark pagination yang diharapkan; installer
kini disamakan dengan wire double. Pada fixture baru, ketiga tes baca integrasi
lulus. Tes destruktif diperluas untuk daftar reservasi tanpa flag tulis dan
logout-all; lulus dengan sesi SQLite serta sesi memory/NO_BACKSLASH_ESCAPES.
Tes destruktif menghapus bookmark fixture: jalankan tes baca lebih dahulu,
atau siapkan database kosong baru untuk mengulang seluruh rangkaian.

Tool `tools/test_member_http.py` memverifikasi alur HTTP nyata: login kembali
ke judul, keranjang, CSV, daftar reservasi, penolakan write, logout-all dan header
keamanan. Lulus pada Rust + MySQL nyata + sesi SQLite. Gate OPAC MySQL lulus
23/23 kasus berlaku, satu kasus memory dilewati. Suite default/crypt/SQLite
juga lulus; SQLite tetap 83 pustaka, 1 behavior dan 22 alur anggota.

Belum merupakan paritas aplikasi PHP/FPM/proxy atau cakupan semua versi server.
Penulisan produksi tetap dibatasi dan tidak ada cutover dilakukan.

### Pencatatan waktu login terakhir (mode uji)

Profil anggota kini membaca last_login tersimpan. Hanya mode penulisan uji yang
memperbaruinya ke UTC sesudah password benar, dengan syarat ID/hash yang baru
diverifikasi. Login normal read-only tidak menulis. IP lama dikosongkan menjadi
NULL agar tidak dipasangkan dengan waktu login baru; IP dari header klien tidak
dipercaya/disimpan. Tidak ada password yang dicatat.

UPDATE tidak diulang otomatis. Kasus tidak ada perubahan karena detik yang sama
diperiksa melalui query baca. Hilangnya balasan write menghasilkan outcome tak
pasti, bukan sesi baru. Timestamp ditulis sebelum rotasi sesi, sehingga bukan
bukti sesi berhasil atau audit durable lintas penyimpanan.

Suite default/crypt/SQLite lulus; SQLite terbaru 83 pustaka, 1 behavior,
23 alur anggota. Tes MySQL 5.7.29 nyata dengan sesi SQLite juga lulus, termasuk
IP lama, predikat kredensial, repeat login dan simulasi balasan write hilang.
Penulisan produksi tetap dinonaktifkan dan belum ada cutover.

### Reservasi keranjang anggota — ADR-0039

- Tombol reservasi keranjang hanya aktif pada mode demo/test yang diizinkan.
- Menggunakan isi sesi terkini dengan CSRF dan pengikatan kredensial; ID anggota
  atau daftar judul kiriman klien ditolak. Hasil per judul, keranjang tetap ada.
- Berhenti pada kesalahan/tulis tak terkonfirmasi, tanpa retry otomatis; judul
  selanjutnya tidak dicoba. Bukan transaksi atomik untuk seluruh keranjang.
- Uji SQLite: 84 library + 1 behavior + 24 flow lulus. Uji tulis destruktif pada
  MySQL 5.7.29 lokal terisolasi + SQLite juga lulus untuk batch dan batas reservasi.
- Belum siap cutover produksi: koordinasi writer admin/PHP, audit/LDAP/TLS,
  pengujian proxy/keamanan/beban dan penerimaan rollback tetap diperlukan.

### Koordinasi reset password admin — ADR-0040

- Menutup celah antara pemeriksaan sesi HTTP dan pengambilan lock reservasi:
  versi kredensial kini dicek kembali di bawah lock MySQL sebelum penulisan.
- Jalur reservasi tunggal dan keranjang memakai API terikat kredensial;
  backend yang belum mendukung menolak, bukan fallback ke penulisan tanpa ikatan.
- Uji MySQL lokal + SQLite lulus untuk simulasi reset password admin dengan
  WRITE lock, kredensial basi, perubahan izin/batas reservasi dan pemulihan.
- Inventaris writer PHP/admin disimpan di `docs/member-writer-inventory.md`.
  Ini belum merupakan uji integrasi PHP/FPM atau persetujuan cutover produksi.

Validasi akhir ADR-0040: default 60+1, member-crypt 74+1+22 flow, SQLite
84+1+25 flow lulus; uji tulis MySQL opt-in dijalankan terpisah dan lulus.

### Bookmark terikat kredensial — ADR-0041

- Jalur HTTP tambah/hapus bookmark kini mengecek versi kredensial di dalam lock
  penulisan. Reset password admin yang membuat sesi basi menolak mutasi.
- Visibilitas judul untuk penambahan dicek ulang di bawah lock katalog; judul
  tersembunyi/hilang menghasilkan 404. Hapus bookmark tetap idempoten dan dapat
  membersihkan referensi yatim. Key bookmark kompatibel PHP tetap dipertahankan.
- Penulisan sekali, tanpa retry otomatis pada hasil tidak terkonfirmasi.
- Validasi lulus: default 60+1; member-crypt 74+1+23 flow; SQLite 84+1+26 flow.
  Uji tulis MySQL 5.7.29 terisolasi juga lulus dengan SQLite, serta dengan sesi
  memori dan NO_BACKSLASH_ESCAPES. Tidak ada aktivasi produksi atau klaim uji
  integrasi PHP/FPM menyeluruh.

### Batas sumber daya klien MySQL — ADR-0042

- Handshake dan setiap perintah SQL memiliki deadline I/O absolut 20 detik;
  respons yang terus menetes tidak lagi memperpanjang timeout.
- Batas paket/query 8 MiB, respons agregat 32 MiB, hasil 256 kolom / 10.000 baris /
  262.144 sel. Pelanggaran menutup koneksi, bukan memotong hasil diam-diam.
- Perbaikan pemeriksaan panjang untuk mencegah overflow dan parsing SQLSTATE
  error terpotong. Tidak menambah retry otomatis penulisan.
- Lulus default 65+1, member-crypt 79+1+23 flow, SQLite 89+1+26 flow; fixture
  MySQL 5.7.29 baru lulus 3/3 uji baca dan uji tulis destruktif dengan SQLite.
- Belum mencakup DNS/connect, total durasi handler, antrean lock, TLS, atau
  pembatalan query di server. Aktivasi produksi tetap tidak dilakukan.

### Integritas respons MySQL — ADR-0043

- Nomor urut paket diperiksa, termasuk wrap 255 ke 0 dan reset tiap perintah.
- Tidak lagi mengiklankan dukungan multi-result. EOF/OK terpotong, hasil tambahan,
  dan byte berlebih pada baris ditolak; NULL tetap didukung.
- Semua error perintah menutup koneksi agar paket sisa tidak terbaca sebagai
  respons berikutnya. Penulisan tetap tanpa retry otomatis.
- Lulus: default 68+1, member-crypt 82+1+23 flow, SQLite 92+1+26 flow.
  Fixture MySQL 5.7.29 baru lulus 3/3 baca dan uji tulis dengan SQLite privat.
- Bukan pengganti TLS atau persetujuan produksi; tidak ada cutover dilakukan.

### Perbaikan handshake autentikasi MySQL — ADR-0044

- Memperbaiki AuthSwitchResponse: scramble dikirim sebagai byte mentah, bukan
  field length-encoded. Byte nol dalam nonce tetap dipertahankan.
- Plugin awal tak dikenal ditolak sebelum mengirim respons turunan kredensial.
  OK terpotong, kelanjutan autentikasi tak didukung dan string tanpa terminator
  ditolak. Full-auth SHA2/cleartext/RSA tetap tidak didukung.
- Lulus default 71+1, member-crypt 85+1+23 flow, SQLite 95+1+26 flow.
  MySQL 5.7.29 native-auth lulus 3/3 baca dan uji tulis SQLite privat.
- Uji caching-SHA2 menggunakan peer simulasi, bukan penerimaan MySQL 8 nyata.
  Tidak menambah TLS dan tidak melakukan cutover produksi.

### Milestone validasi terpadu — ADR-0045

- Membatasi percobaan koneksi TCP MySQL: anggaran bersama 5 detik/8 alamat setelah
  resolusi DNS. Antrean mutex koneksi baca dibatasi 3 detik; bukan deadline total
  request atau batas tunggu semua session/SQLite lock.
- Menambah runner `tools/test_member_suite.py`: regresi tiga fitur, fixture baru,
  baca MySQL, HTTP anggota write-off, gate OPAC, kemudian uji tulis SQLite.
  Guard PHP CLI/HTTP terisolasi dapat dijalankan dalam perintah yang sama.
- Runner menolak DB non-kosong, membersihkan konfigurasi turunan, memakai port
  dinamis dan sesi privat, menghentikan child saat gagal/timeout/SIGTERM, serta
  menandai laporan gagal sebelum preflight agar hasil lama tidak menyesatkan.
- Rangkaian nyata lulus: default 73+1, crypt 87+1+23 flow, SQLite 97+1+26 flow;
  MySQL 3/3 baca, HTTP anggota, OPAC 23/23 applicable, uji tulis SQLite;
  PHP WASM 8 CLI/lint dan 16 kasus HTTP. Uji keselamatan runner juga lulus.
- Panduan pengulangan dan batas bukti: `docs/member-validation.md`. Tidak ada
  cutover produksi, penghapusan database, commit atau push dilakukan.

### Migrasi fitur laporan pinjaman PHP ke Rust — ADR-0046

- Mem-port fitur `download_member_loan_report.inc.php` ke `member_report.rs`:
  profil anggota serta gabungan pinjaman aktif/riwayat, tampilan cetak HTML dan
  CSV. Tautan baru ada di area anggota dan halaman daftar pinjaman.
- Alias GET/HEAD URL PHP lama didukung saat diarahkan ke Rust. Sesi PHP tidak
  diterima; anggota hanya dapat membaca datanya sendiri. POST lama ditolak.
- Baris pinjaman dibaca sekali, bukan gabungan halaman yang bisa bergeser.
  Batas 1.000 pinjaman/512 KiB data mentah menghasilkan 413, tanpa file parsial.
- Escaping HTML, pengamanan formula CSV, CSS cetak kompatibel CSP dan header
  privat tetap berlaku; kredensial dicek ulang sebelum respons diberikan.
- Koreksi dokumentasi: skrip unduhan anggota PHP menghasilkan HTML, bukan PDF.
  Rust menyediakan HTML cetak/CSV, bukan kesetaraan markup atau PDF server-side.
- Validasi terpadu lulus: default 73+1, crypt 88+1+24 flow, SQLite 98+1+27 flow;
  MySQL 3/3 baca, HTTP laporan/CSV/HEAD, OPAC 23/23 applicable, uji tulis + laporan
  lintas halaman/batas ukuran; PHP terisolasi 8 CLI + 16 HTTP; 6 safety runner.
- Belum cutover produksi. Dua alias unduhan khusus aktif/riwayat belum di-port.

### Fondasi backend tahap pertama — ADR-0047

- Binary memakai konfigurasi bertipe dan validasi startup; URL DB salah tidak
  diam-diam beralih ke memori. Error statis tidak menyertakan nilai rahasia.
- Batas koneksi, request keep-alive serta deadline baca/tulis HTTP terpusat dan
  dapat dikonfigurasi; pengaman penulisan/schema tidak berubah.
- Menambah log JSON teredaksi dan X-Request-ID buatan server. Tidak mencatat
  path/query, cookie, body, identitas anggota atau kredensial DB. Event disiapkan
  sebelum pengiriman; bukan bukti delivery atau durable audit.
- Lulus runner MySQL penuh: default 77+1+2 binary, crypt 92+1+24 flow+2 binary,
  SQLite 102+1+27 flow+2 binary; MySQL baca 3/3, HTTP anggota, OPAC 23/23,
  tulis SQLite privat dan 6 uji safety. PHP tidak diubah/diuji ulang tahap ini.
- Batas nyata: crates.io index/arsip tidak dapat diakses, sehingga Axum/Tokio,
  SQLx, Askama/MiniJinja dan tracing BELUM dipasang/diimplementasikan. Build
  offline tetap berjalan; rencana adopsi dan detail hasil di
  `docs/backend-foundation.md`. Usulan fondasi no. 3 belum seluruhnya selesai.

### Dua unduhan anggota dan pemeriksaan PR — ADR-0048

- Mem-port unduhan pinjaman aktif dan riwayat ke alias GET/HEAD Rust, berupa
  HTML attachment serta CSV. Canonical scope=all/current/history didukung.
- Filter scope dilakukan sebelum limit; riwayat kecil tidak ditolak karena
  jumlah pinjaman aktif besar. Renderer menolak scope salah, tidak memotong data.
- Menyiapkan template workflow PR: matriks fitur offline, safety runner/guard PHP native,
  serta suite MySQL 5.7.44 terisolasi. Tidak ada deployment atau DB produksi.
- Lulus lokal: default 78+1+2 binary; crypt 94+1+25 flow+2 binary; SQLite
  104+1+28 flow+2 binary; safety 6; HTTP memory/SQLite dengan alias HTML/CSV/HEAD;
  OPAC 23/23 applicable. Server MySQL lama hilang; uji SQL baru belum dijalankan
  lokal, hasil CI harus diverifikasi terpisah. Bukan klaim siap produksi.

Catatan CI: push workflow ditolak karena GitHub App tidak memiliki izin
`workflows`. Template disimpan di docs/ci/rust-validation.yml dan BELUM AKTIF.
Maintainer berizin perlu memasangnya ke .github/workflows/rust-validation.yml;
tidak ada klaim uji MySQL baru/CI lulus. Perubahan fitur tetap dapat di-push.

### ADR-0049 — bukti validasi terikat revisi

Runner mencatat revisi/status dirty sebelum dan sesudah, timestamp dan versi
skema laporan. Verifier offline menolak tahapan hilang/gagal/duplikat/terbalik,
revisi berbeda, tree kotor, laporan kedaluwarsa dan klaim cakupan yang tidak
didukung. Sebelas tes safety lulus. Ini pemeriksaan konsistensi, bukan tanda
tangan atau persetujuan produksi. Template CI tetap nonaktif; MySQL/Rust/PHP
belum diuji ulang pada tahap ini. Tidak ada perubahan schema/writer produksi.
