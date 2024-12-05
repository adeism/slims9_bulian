# 📚 SLiMS 9 Bulian

## 🖥️ Senayan Library Management System (SLiMS)

SLiMS versi 9 dengan kode nama Bulian adalah perangkat lunak sumber terbuka gratis untuk manajemen perpustakaan.

### 🌟 Fitur Utama
- 📖 Manajemen sumber daya perpustakaan
- 📑 Administrasi sirkulasi koleksi
- 👥 Manajemen keanggotaan
- 📊 Pengambilan stok
- 🔍 Pencarian dan katalogisasi

🔒 **Lisensi**: GNU GPL versi 3

### 🛠️ Persyaratan Sistem

| Komponen | Spesifikasi |
|----------|-------------|
| 💡 PHP | Versi >= 8.1 |
| 🗃️ Database | MySQL 5.7 atau MariaDB 10.3 |
| ✅ Ekstensi PHP | GD, gettext, mbstring |

## 💼 Instalasi dengan Docker

#### Langkah Instalasi

1. **Clone Repository**
```bash
git clone https://github.com/adeism/slims9_bulian.git
cd slims9_bulian
```

2. **Ubah konfigurasi file .env (dalam folder docker)**
```bash
# default file .env dengan konfigurasi berikut
MYSQL_ROOT_PASSWORD=rootpassword
MYSQL_DATABASE=my_database
MYSQL_USER=my_user
MYSQL_PASSWORD=my_password
PMA_PORT=8080
```

3. **Build Image Docker**
```bash
docker-compose build
```

4. **Jalankan Aplikasi**
```bash
docker-compose up -d
```

### 🌐 Akses Aplikasi

| Aplikasi | URL |
|----------|-----|
| 🌍 Web SLiMS | http://localhost |
| 🗂️ phpMyAdmin | http://localhost:8080 |
