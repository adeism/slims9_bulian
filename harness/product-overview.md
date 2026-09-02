# Tautan — Product Overview

## Ringkasan
Tautan adalah satu platform untuk membuat, mengelola, dan membagikan aset digital:
- Shortlink
- Microsite
- Form Maker drag-and-drop
- Halaman berbagi publik/berpassword
- QR code
- Analitik
- Workspace organisasi
- Moderasi dan keamanan

Satu akun, satu dashboard, satu domain publik, satu permission, satu analitik, satu billing.

## Positioning
> Satu aplikasi untuk membuat link pendek, microsite, formulir, QR code, dan halaman berbagi aman.

Target: personal, kreator, UMKM, sekolah/kampus, perpustakaan, panitia acara, organisasi, instansi publik.

Diferensiasi:
- Shortlink + microsite satu produk
- Form Maker no-code dengan template
- Halaman privat password + idle timeout
- QR dinamis terhubung analitik
- Template pendidikan & perpustakaan
- Workflow approval organisasi

## Struktur Navigasi
Home / Overview, Campaigns, Shortlinks, Microsites, Forms, QR Studio, Analytics, Assets, Templates, Workspace, Billing, Admin Center

## Entitas Terpadu
Workspace = ruang kerja utama
Campaign = wadah opsional gabungan aset (microsite utama + shortlinks + forms + QR + media + UTM + jadwal + dashboard)

Contoh:
```
Campaign: Seminar Literasi Digital 2026
├── Microsite: /seminar-literasi
├── Form: /f/daftar-seminar
├── Shortlink: /daftar-lit
├── QR: Poster seminar
└── Analytics: klik, scan, view, submission
```

## First Value Goals (per role)
- Personal: Buat shortlink pertama dalam 3 langkah, dapat link aktif + QR
- Creator: Buat microsite dari template dalam 2 menit, publish & share
- UMKM: Buat campaign katalog + form pemesanan, dapat QR produk
- Perpustakaan: Buat form usulan koleksi, dapat shortlink + export CSV
- Panitia: Buat campaign event lengkap (landing + form daftar + QR poster) dalam 5 langkah
- Super Admin: Buat reserved slug, audit log terlihat

Semua role: Masuk → Satu tugas nyata → Hasil jelas cepat → Baru konfigurasi detail.

## Modes
- Demo Mode: no password, pick role/sample user, sample data, easy to disable via feature flag
- Production Mode: password required, real data

Demo Mode controlled by config `core/config.js` flag `demoMode.enabled`.

## Tech Stack MVP
- Frontend: Vanilla JS + Tailwind CDN, modular files <1000 lines
- Storage: localStorage for Demo, PHP/MySQL ready for Production (modular monolith)
- QR: qrcode.js + canvas
- Drag-and-drop: native HTML5 DnD
- No heavy framework to keep first-run simple

## Roadmap
Fase 1 MVP (current): Auth role, workspace personal, shortlink, microsite basic, form drag-drop basic, template gallery, public/password page, idle timeout 60s, QR statis, analytics dasar, admin moderation, audit log.
Fase 2: Workspace org, custom domain, QR dinamis, conditional logic, file upload aman, email notif, campaign wizard, export advanced.
Fase 3: API, webhook, payment, SLiMS integration, approval workflow.
Fase 4: AI generator, Ollama local LLM, SSO.

## Definition of Done MVP
- Shortlink 3 langkah max
- Slug <5 char ditolak untuk user biasa, super admin boleh dengan audit
- Microsite dari template
- Form drag-drop + publish + embed microsite
- Form share via shortlink + QR
- Halaman publik/password
- Session password berakhir idle 60 detik
- Response view + export
- Dashboard funnel traffic→submission
- Draft tidak akses publik
- Data privat tidak bocor
- Usable desktop & Android
