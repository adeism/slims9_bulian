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
  baca terhadap skema SLiMS. Backend MySQL saat ini hanya-baca.
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
- Prototipe reservasi **hanya untuk backend memory** dan hanya jika diaktifkan
  terpisah. Pengecekan duplikasi dan batas dalam satu proses dilakukan secara
  atomik. Data demo hilang saat proses dimulai ulang dan **tidak** menulis
  reservasi ke database SLiMS.

Catatan keamanan: autentikasi area anggota belum mencakup migrasi MD5 lama,
LDAP, CAPTCHA, audit, perubahan password, atau penegakan status keanggotaan.
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
| `cargo test --offline` | 43 unit test dan 1 behavior test lulus; 1 tes integrasi MySQL diabaikan secara default |
| `cargo test --offline --features member-crypt` | 50 unit test, 1 behavior test, dan 7 tes alur anggota lulus; 2 tes MySQL opt-in diabaikan |
| `cargo test --offline --features member-sqlite` | 57 unit test, 1 behavior test, dan 9 tes alur anggota lulus; 2 tes MySQL opt-in diabaikan |
| Dua tes `member_mysql` yang dijalankan eksplisit dengan sesi SQLite dan test double | 2/2 lulus |
| Gate pada backend memory | 23/23 kasus yang berlaku lulus; 1 kasus khusus MySQL dilewati |
| `tools/mysql_roundtrip.sh` | 23/23 kasus yang berlaku lulus melalui protokol MySQL ke test double; 1 kasus khusus memory dilewati |

Fixture berisi 24 kasus total. Test double menggunakan SQLite di belakang
protokol MySQL; ini berguna untuk menguji protokol dan query, tetapi **bukan
server MySQL/MariaDB asli**. Pengujian di atas juga belum membandingkan respons
dengan instance PHP SLiMS yang hidup. Pengujian member mencakup CSRF, rotasi dan
pencabutan sesi, migrasi SQLite, batas login, isolasi anggota, serta reservasi
demo, bukan sertifikasi keamanan produksi.

## 4. Yang belum dilakukan / rencana berikutnya

Urutan di bawah merupakan rencana kerja, bukan janji tanggal penyelesaian.

1. **Validasi terhadap aplikasi asli:** siapkan lingkungan uji PHP + MySQL/
   MariaDB dengan data yang sama; rekam dan bandingkan API, OPAC, login anggota,
   profil, dan riwayat. Perbaiki perbedaan, termasuk perilaku FULLTEXT, timezone,
   collation, dan pengaturan SQL. Perlu diperluas juga pengujian regresi dan
   keamanan pada data non-demo.
2. **Selesaikan area anggota:** dukung alur autentikasi yang relevan pada PHP,
   termasuk migrasi hash MD5 ke bcrypt secara aman, konfigurasi auth/CAPTCHA,
   perubahan password, bookmark, basket, dan pencatatan last-login/audit.
   Rancang sesi dan pencabutan yang aman untuk deployment multi-host, serta
   pembatasan login terdistribusi dan perlindungan terhadap denial of service.
3. **Reservasi nyata dan jalur tulis:** rancang konsistensi, audit, dan mekanisme
   pemulihan yang aman saat PHP dan Rust menulis bersamaan. Tabel `reserve`
   pada skema bawaan menggunakan MyISAM; transaksi Rust saja tidak cukup untuk
   menjamin batas atau mencegah duplikasi lintas penulis. Belum ada penulisan
   reservasi ke MySQL.
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
