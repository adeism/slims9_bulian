---
title: CLI-Anything
description: Menjadikan SLiMS dapat dikendalikan oleh agen AI, skrip, dan cron job melalui antarmuka baris perintah yang aman — dengan gerbang tulis, dry run, dan jejak audit.
---

Fitur `Console`/`Tarsius` membuat SLiMS dapat menjalankan pekerjaan di balik layar.
**CLI-Anything** melangkah satu tingkat lebih jauh: seluruh modul yang biasa dipakai
pustakawan lewat antarmuka web (katalog, koleksi, keanggotaan, sirkulasi, laporan)
dibuka sebagai **permukaan perintah yang ramah mesin**, tanpa menulis ulang aturan
perpustakaan.

Pendekatan ini diadaptasi dari proyek [CLI-Anything](https://github.com/HKUDS/CLI-Anything)
yang mengubah perangkat lunak apa pun menjadi *agent-native*: agen AI bekerja lebih
andal lewat perintah + keluaran JSON ketimbang menebak tombol pada antarmuka web.

> Fitur ini **tambahan**, bukan pengganti GUI. Semua operasi tulis tetap melewati
> kelas inti SLiMS (`member`, `circulation`), sehingga aturan pinjam, denda,
> reservasi, `loan_history`, dan log sistem berperilaku sama seperti saat
> pustakawan mengklik menu Sirkulasi.

### Kenapa bukan menembak basis data langsung?

| Pendekatan | Akibat |
|---|---|
| SQL langsung ke tabel `loan`, `item`, `biblio` | aturan pinjam, denda, `loan_history`, dan hook plugin terlewati; rawan data rusak tanpa jejak audit |
| Meniru form admin (session + CSRF) | rapuh: berhenti bekerja setiap kali tema/versi berubah |
| **CLI-Anything (dokumen ini)** | memakai logika inti SLiMS, keluaran JSON, gerbang tulis, audit |

### Arsitektur

```
agen / pustakawan / cron
        │  cli-anything-slims  (paket Python, opsional)
        ├──► HTTP bridge   plugins/cli_anything/     index.php?p=api/cli-anything/v1/...
        └──► Console       plugins/Commands/         php index.php clianything:...
                                   │
                             SLiMS core ──► circulation / member ──► MySQL
```

Dua pintu masuk, **satu kontrak keluaran**. Agen dapat berpindah dari laptop
(Http bridge) ke server (console) tanpa mengubah perintah.

### Berkas yang disiapkan

| Bagian | Lokasi | Fungsi |
|---|---|---|
| Layanan bersama | `plugins/Commands/Support/` | kueri, gerbang, backup, audit, kode galat |
| Perintah console | `plugins/Commands/CliAnything*.php` | `clianything:*` |
| HTTP bridge | `plugins/cli_anything/` | endpoint ber-token |
| Harness agen | `cli-anything/` | CLI Python `cli-anything-slims` + `SKILL.md` |
| Konfigurasi | `config/cli_anything.sample.php` | token, gerbang, kebijakan PII |

---

## Fase 0 — HTTP bridge (baca saja)

Bridge adalah plugin SLiMS biasa sehingga **tidak aktif sampai anda mengaktifkannya**.
Plugin mendaftarkan rute lewat hook `custom_api_route` yang sudah dipakai berkas
`api/v1/routes.php`.

### Langkah mengaktifkan

1. Salin konfigurasi dan buat token acak:

    ```bash
    cp config/cli_anything.sample.php config/cli_anything.php
    php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
    ```

    Isi hasilnya pada kunci `token`.

2. Aktifkan plugin:

    ```bash
    php index.php plugin:list      # catat id plugin "CLI-Anything Bridge"
    php index.php plugin:activate <id>
    ```

    Cara lain: menu **System → Plugins** pada area admin.

3. Uji dari terminal:

    ```bash
    curl -s -H "X-CLI-Anything-Token: <token>" \
      "https://slims.sekolah.id/index.php?p=api/cli-anything/v1/status" | jq '.data.gates'
    ```

### Endpoint

| Metode | Path | Menulis | Keterangan |
|---|---|---|---|
| GET | `/status` | tidak | diagnostik: gerbang, jumlah record, pemeriksaan |
| GET | `/biblio?q=&limit=&offset=&sort=` | tidak | pencarian katalog bertingkat (persis → kata dipendekkan → "mungkin maksud Anda"), diurutkan relevansi/kepopuleran |
| GET | `/biblio/{id}` | tidak | metadata + pengarang + subjek + daftar eksemplar |
| GET | `/item/{item_code}` | tidak | satu eksemplar (barkode) + status pinjamnya |
| GET | `/member/{id}` | tidak | kartu anggota + ringkasan pinjaman (PII disamarkan) |
| GET | `/loan?status=&member=&limit=` | tidak | `active`, `overdue`, `returned`, `all` |
| GET | `/report/summary` | tidak | ringkasan koleksi, keanggotaan, sirkulasi, denda |
| POST | `/loan/checkout` | **ya** | `{"member":"M1","items":"B1,B2","commit":true}` |
| POST | `/loan/checkin` | **ya** | `{"loanid":12,"commit":true}` |
| POST | `/loan/extend` | **ya** | `{"loanid":12,"commit":true}` |

Autentikasi memakai satu token bersama: header `X-CLI-Anything-Token` atau
`Authorization: Bearer <token>`. Token kosong berarti bridge tidak mendaftarkan
rute sama sekali.

---

## Fase 1 — Perintah console

Perintah mengikuti pola Console yang sudah ada (`plugins/Commands`, namespace
`Commands\`), sehingga otomatis terdaftar:

```bash
php index.php clianything:doctor
php index.php clianything:biblio:list --q="sejarah" --limit=5
php index.php clianything:biblio:list --q="sejarh"            # toleran salah ketik
php index.php clianything:biblio:list --sort=popular --limit=5 # paling sering dipinjam
php index.php clianything:loan:list --status=overdue --json
```

Setelah sekali menjalankan `php index.php tarsius`, bentuk singkat `php tarsius ...`
juga tersedia (lihat halaman [Tarsius](03-Tarsius.md)).

| Perintah | Kegunaan | Menulis |
|---|---|---|
| `clianything:doctor` | gerbang aktif/tidak, jumlah record, pemeriksaan deployment | tidak |
| `clianything:biblio:list {--q=} {--sort=} {--limit=}` | pencarian katalog (`sort`: relevance, popular, newest, title) | tidak |
| `clianything:biblio:get {id}` | detail bibliografi | tidak |
| `clianything:item:get {code}` | satu eksemplar | tidak |
| `clianything:member:get {id}` | anggota + ringkasan pinjaman | tidak |
| `clianything:loan:list` | daftar sirkulasi per status | tidak |
| `clianything:report` | ringkasan angka | tidak |
| `clianything:loan:checkout` | peminjaman | **gerbang** |
| `clianything:loan:checkin` | pengembalian | **gerbang** |
| `clianything:loan:extend` | perpanjangan | **gerbang** |

Semua perintah menerima `--json`. Tanpa `--json`, keluaran ditampilkan sebagai
tabel yang mudah dibaca manusia.

---

## Fase 2 — Gerbang tulis

Perintah tulis **tidak menulis** kecuali keempat syarat berikut terpenuhi:

| Gerbang | Bawaan | Lokasi |
|---|---|---|
| `allow_write` | **mati** | `config/cli_anything.php` / `SLIMS_CLI_ANYTHING_ALLOW_WRITE` |
| `--commit` (atau `commit=1`) | **mati** | argumen perintah / isi permintaan |
| `allow_bulk` (lebih dari satu record) | **mati** | `config/cli_anything.php` / `SLIMS_CLI_ANYTHING_ALLOW_BULK` |
| backup basis data sebelum tulis massal | selalu | `files/backup/cli-anything-*.sql` |

Tanpa `--commit`, perintah menjadi **dry run sebenarnya**: SLiMS menjalankan mesin
aturan pinjam (`addLoanSession`, `countOverdueValue`, hitungan masa pinjam, cek
reservasi) lalu melaporkan rencana — tanggal jatuh tempo, denda, alasan penolakan —
sambil membuang sesi sementara.

```bash
php index.php clianything:loan:checkout --member=M0001 --items=B00001,B00002
```

```json
{
  "ok": true,
  "schema": "1.0",
  "command": "loan.checkout",
  "data": {
    "dry_run": true,
    "committed": false,
    "ready": true,
    "items": [
      {"item_code": "B00001", "status": "ready", "due_date": "2026-12-11"},
      {"item_code": "B00002", "status": "ready", "due_date": "2026-12-11"}
    ]
  }
}
```

Setelah rencana disetujui, ulangi dengan `--commit`. Sifatnya **all-or-nothing**:
bila satu item ditolak aturan pinjam, seluruh transaksi dibatalkan dengan kode
`LOAN_REJECTED` dan tidak ada data yang ditulis.

Setiap penulisan yang berhasil dicatat pada `system_log` dengan lokasi
`CLI-Anything` dan akun staf yang dikonfigurasi, sehingga aktivitas agen mudah
dipisahkan dari aktivitas pustakawan pada **System → System Log**.

Hal-hal yang **sengaja tidak disediakan**: perintah hapus bibliografi/anggota,
pembuatan/penyuntingan katalog, dan impor MARC massal. Pekerjaan tersebut tetap
keputusan manusia di GUI sampai ada perintah bergerbang dengan rollback teruji.

---

## Kontrak keluaran

Satu bentuk untuk semua jalur:

```json
{"ok": true, "schema": "1.0", "command": "loan.list", "data": {...},
 "meta": {"generated_at": "...", "write_enabled": false, "bulk_enabled": false}}

{"ok": false, "schema": "1.0",
 "error": {"code": "WRITE_DISABLED", "message": "...", "context": {}}}
```

Kode galat yang perlu ditangani pemanggil: `WRITE_DISABLED`, `BULK_DISABLED`,
`MEMBER_NOT_FOUND`, `ITEM_NOT_FOUND`, `BIBLIO_NOT_FOUND`, `LOAN_NOT_FOUND`,
`MEMBER_EXPIRED`, `MEMBER_PENDING`, `LOAN_REJECTED`, `ITEM_RESERVED`,
`RENEW_LIMIT_REACHED`, `UNAUTHORIZED`, `PII_REVEAL_DISABLED`,
`BRIDGE_NOT_CONFIGURED`.

Status HTTP mengikuti kode galat (`401`, `403`, `404`, `405`, `409`, `500`,
`503`). Untuk console, kode keluar: `0` sukses, `1` galat backend, `2` galat
penggunaan, `3` ditolak gerbang.

---

## Memakai harness Python (opsional)

Console dan HTTP bridge sudah bisa dipakai langsung dengan `php`/`curl`. Bila anda
ingin pengalaman perintah yang lebih nyaman untuk agen AI, pasang harness-nya di
komputer operator (bukan di server):

```bash
pip install ./cli-anything

export SLIMS_BASE_URL="https://slims.sekolah.id"
export SLIMS_CLI_ANYTHING_TOKEN="<token>"
# atau, bila dijalankan di server:
# export SLIMS_ROOT="/var/www/slims"

cli-anything-slims doctor
cli-anything-slims biblio list --q "metodologi" --limit 5
cli-anything-slims loan checkout --member M0001 --items B00001 --commit
```

Harness menyertakan `skills/cli-anything-slims/SKILL.md`, yaitu dokumentasi
berformat mesin yang dibaca agen: daftar perintah, kontrak JSON, kode galat, dan
aturan keselamatan. Berkas inilah "halaman manual" versi agen.

---

## Keamanan dan privasi

* Token dibandingkan dengan `hash_equals` dan tidak pernah dikembalikan pada respons;
  `doctor` hanya melaporkan apakah token sudah diatur.
* Plugin harus diaktifkan lebih dulu — tidak ada rute yang terdaftar bila token kosong.
* `redact_pii` menyamarkan surel, telepon, faks, dan alamat anggota pada **setiap**
  keluaran; klien HTTP tidak dapat membukanya kecuali operator mengaktifkan
  `allow_pii_reveal`.
* Gunakan HTTPS. Token pada header hanya seprivat transport yang membawanya.
* Arahkan `user_id` ke akun staf khusus agar audit mudah dipisahkan.
* Jalankan harness di komputer operator/CI bila tidak ingin memasang Python di server.

---

## Penyelesaian masalah

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| `BRIDGE_NOT_CONFIGURED` (503) | token kosong | isi `token` pada `config/cli_anything.php` |
| `UNAUTHORIZED` (401) | header tidak terkirim | sertakan `X-CLI-Anything-Token` |
| HTML, bukan JSON | URL bukan endpoint bridge | gunakan `index.php?p=api/cli-anything/v1/...` |
| `WRITE_DISABLED` | `allow_write` masih `false` | aktifkan setelah uji dry run |
| `BULK_DISABLED` | >1 record tanpa `allow_bulk` | pecah per item atau aktifkan `allow_bulk` |
| `NO_BACKEND` (harness) | belum ada `SLIMS_BASE_URL`/`SLIMS_ROOT` | atur salah satu variabel |
| `tarsius exited ...` | `php` CLI tanpa ekstensi `mysqli`/`mbstring`/`gettext` | pasang ekstensi tersebut |

Tersedia juga `cli-anything/scripts/e2e.sh` pada repositori SLiMS untuk menguji
seluruh alur (doctor → item → dry run → commit → check-in) di lingkungan
docker-compose.
