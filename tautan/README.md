# Tautan — Satu tempat untuk semua link, halaman, form, dan campaign

## Quick Start (Fewer Steps to First Value)

1. Buka `tautan/index.html` di browser, atau jalankan:
   ```
   php -S 0.0.0.0:8000 -t tautan
   ```
   Lalu buka http://localhost:8000

2. Setup 5 langkah (satu file):
   - Mode: Demo (tanpa password, direkomendasikan) vs Production
   - Role: Personal, Creator, UMKM, Sekolah, Perpustakaan, Panitia, Organisasi, Super Admin
   - Sample data: Ya (direkomendasikan, langsung lihat hasil) / Tidak
   - Default settings: bahasa, timezone, tema auto-detect, bisa skip
   - Review & mulai

3. Setelah setup, workspace pertama otomatis dibuat (tidak ada layar kosong mati)

4. Dashboard menampilkan:
   - Overview ringkasan aset
   - + Buat Baru (shortlink, microsite, form, QR, campaign lengkap, dari template)
   - Checklist aktivasi 3-5 item
   - Lanjutkan dari terakhir
   - Aksi cepat

5. Buat shortlink pertama dalam 3 langkah:
   - Klik + Buat Baru → Shortlink
   - Masukkan URL tujuan
   - Klik Buat → dapat link aktif + QR + copy

## Struktur

```
core/       → config, storage, router, auth, resolver, security, analytics (permanent, no demo)
modules/    → shortlinks, microsites, forms, qr, campaigns, analytics, workspace, media, templates (<1000 lines each)
onboarding/ → setup.js single file, checklist, guide
tutorials/  → quick, detailed, help-center
demo/       → sample-data, demo-users (isolated, easy to remove)
shared/     → components (Button, Modal, Toast, EmptyState, HelpBubble), utils (slug, validators, svg)
docs/flows/ → per role & feature flow docs
harness/    → product-overview, architecture-map, role-matrix, feature-flows, ui-rules, coding-rules, changelog, agent-handoff, default-settings, feature-flags
assets/     → logo.svg, bg-pattern.svg (SVG separate, lightweight)
```

## Demo Mode

- Controlled by `core/config.js` flag `demoMode.enabled`
- No password, pick role/sample user
- Sample data: campaign seminar, shortlink, microsite, form, QR, analytics
- Easy to disable: set flag false, remove demo/ folder, remove demo imports in onboarding/setup.js and core/auth.js

## Production Mode

- Password required, real data (future Laravel backend)
- Hash Argon2id/bcrypt, session server-side, idle timeout 60s, revoke version, audit log

## Fitur MVP

- Shortlink: slug 5-50 user, 1-50 super admin dengan audit, 301/302, UTM, expiry, password, pause, QR, safe browsing block localhost/private IP/metadata, reserved slug
- Microsite: drag-drop blok (profile, text, button, image, video, social, form, QR, divider, product, announcement), tema preset, draft/publish, SEO, favicon, custom slug, public/password, idle timeout 60s, expiry, revoke sessions, preview mobile/desktop
- Form Maker: 3 panel Field Library→Canvas→Properties, drag-drop, field types 14, validasi, template, password, limit, one per email, success message/redirect, response manager, export CSV/XLSX/JSON, embed, shortlink & QR
- Secure Share: password Argon2id, session server-side, HttpOnly/Secure cookie simulasi, idle timeout server-side, no-store, revoke version, rate limit 5 percobaan lockout 60s
- QR Studio: static MVP, PNG/SVG, warna/logo, preset, tracking, download/duplicate, scan stats
- Analytics: funnel QR scan→click→view→submission, views, clicks, unique, device/OS/browser/referer/UTM, form starts/completions, IP hash, bot filtering, daily aggregation
- Campaign Wizard: 5 langkah identitas→microsite→form→link & QR→publish, auto-generate aset, unified analytics
- Media Library: upload, folder/tag, search, optimasi, alt text, size limit, signed URL simulasi
- Template Center: kategori Event/Education/Library/UMKM/Creator/Portfolio/Survey/Registration/Contact/Complaint, recommended badge
- Workspace: members, role matrix (owner, admin, editor, contributor, viewer), branding, audit log
- Admin Center: reserved slugs, audit logs, abuse reports (super admin)

## UX Rules Implemented

- Fewer steps always better, one screen one main action, one flow one clear goal
- Smart/safe/recommended defaults, auto-detect language/timezone/theme/date format
- Skip non-critical setup, change later in Settings
- Review page before setup ends, progress indicator, autosave setup progress
- First workspace/project/item auto-create, no dead empty screen
- Empty states explain what area is for + next best action + sample/template CTA
- Progressive disclosure, dense useful info not overwhelming, compact clear calm, no fake decorative complexity
- Responsive desktop+mobile, SVG backgrounds separate lightweight
- Direct-to-task onboarding, teach by doing, short interactive guide only first task, contextual help when friction, dismissible, reopenable, help center always available, one beginner hint per critical screen, reset onboarding in Settings
- Checklist 3-5 items, each leads to correct screen, first pre-completed when sample data, focus activation
- Continue-where-you-left-off, recent actions, one-click start, role-based templates, command palette placeholder
- One setup file until defaults stable, then split into smaller modules

## Security

- Slug policy, reserved slug, safe browsing anti-SSRF, audit log
- Password hash simulation, session version, idle timeout server-side validation, rate limit
- Draft not public, private data not leak via HTML/cache/asset/API
- Cache-Control private no-store for secure pages

## Harness Docs

Lihat `harness/` untuk product-overview, architecture-map, role-matrix, feature-flows, ui-rules, coding-rules, changelog, agent-handoff, default-settings, feature-flags

Semua docs selalu match app.

## Definition of Done MVP

- Shortlink 3 langkah max ✅
- Slug <5 ditolak user biasa ✅, super admin boleh dengan audit ✅
- Microsite dari template ✅
- Form drag-drop + publish + embed microsite ✅
- Form share via shortlink & QR ✅
- Halaman publik/password ✅
- Sesi password berakhir idle 60 detik ✅
- Respons view & export ✅
- Dashboard funnel traffic→submission ✅
- Draft tidak akses publik ✅
- Data privat tidak bocor ✅
- Usable desktop & Android ✅

## Next

- Laravel backend modular monolith
- Custom domain + SSL
- QR dinamis
- File upload virus scan
- Email notification
- API & webhook
- SLiMS integration
- AI generator Ollama

## License

Mengikuti SLiMS GPL v3, Tautan module MIT for frontend demo.
