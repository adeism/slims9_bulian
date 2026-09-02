# Feature Flows — Tautan

> For every role and feature: goal, entry point, shortest path, success state, error state, help state, next action

## 1. Shortlink Feature

**Goal**: Create shortlink that redirects to target URL, with analytics.

**Entry Points**:
- Dashboard → + Buat Baru → Shortlink
- Shortlinks page → Buat Shortlink
- From microsite/form detail → Generate shortlink
- Campaign wizard step 4

**Shortest Path (3 steps max)**:
1. Click + Buat Baru → Shortlink
2. Input target URL (auto-detect, smart default slug from title or random 5 chars) + optional custom slug (min 5 for user)
3. Click Buat → success with link + QR + copy button

**Success State**:
- Link active, shows: short URL, target, clicks 0, QR preview, share buttons, analytics link
- Toast: "Shortlink aktif! Siap dibagikan"
- Checklist item auto-complete

**Error States**:
- Slug <5 chars for normal user → inline error: "Slug minimal 5 karakter. Super admin dapat 1-4 dengan audit."
- Slug taken → suggest 3 alternatives: slug-1, slug-2, random
- Invalid URL (localhost, private IP, metadata endpoint) → error: "URL tidak aman, tidak boleh localhost/private IP"
- Reserved slug → error: "Slug dilindungi"
- Target URL empty → inline required

**Help State**:
- Contextual bubble: "Slug adalah nama pendek di belakang domain. Contoh: tautan.id/promo-literasi"
- Help link: "Lihat panduan shortlink"
- Beginner hint on Shortlinks empty state

**Next Action**:
- Generate QR
- Add to campaign
- Set expiry/password
- View analytics
- Copy & share

---

## 2. Microsite Builder

**Goal**: Build landing page from blocks, publish.

**Entry**:
- + Buat Baru → Microsite
- Microsites → Buat Microsite
- Template gallery → Use template
- Campaign wizard step 2

**Shortest Path**:
1. Pick template (recommended default: Profile + Text + Button)
2. Edit title, add one block (drag from library)
3. Publish → get /m/slug live

**Success**:
- Preview mobile/desktop toggle works
- Live URL, views counter
- Confetti + "Microsite publish! Bagikan sekarang"

**Error**:
- Slug conflict → suggestion
- Draft access attempt public → 404 or "Draft tidak publik"
- Block config invalid → inline validation

**Help**:
- Direct-to-task onboarding: highlight canvas, show drag first block
- Contextual: "Drag blok dari kiri ke kanvas"
- Empty state: "Microsite kosong. Mulai dari template atau tambah blok Profile"

**Next**:
- Add form block
- Generate shortlink & QR
- Set password protection
- View analytics

---

## 3. Form Maker

**Goal**: Create form via drag-drop, publish, collect responses.

**Entry**:
- + Buat Baru → Form
- Forms → Buat Form
- From microsite editor → Add Form block
- Campaign wizard step 3
- Template → Form template

**Shortest Path**:
1. Pick template (Pendaftaran, Usulan Koleksi, Kontak)
2. Drag 1 field to canvas (default: Name, Email, Phone auto-added)
3. Publish → get /f/slug + shortlink + QR

**Success**:
- Form live, test submission works
- Response appears in Response Manager
- Funnel: Form starts, completions

**Error**:
- Field validation fail server-side → show error per field
- Limit response reached → "Form telah mencapai batas respons"
- One response per email duplicate → "Email sudah pernah submit"

**Help**:
- Three panels explained in quick tutorial: Field Library → Canvas → Properties
- Keyboard alternative: Tab + Enter to add field
- Help bubble: "Klik field untuk atur validasi & logic"
- Empty canvas: "Drag field pertama dari kiri"

**Next**:
- Embed to microsite
- Generate shortlink/QR
- Set password/login-only
- View responses & export CSV
- Email notification

---

## 4. Campaign Lengkap (Unified)

**Goal**: Create campaign with microsite + form + shortlink + QR + analytics in one flow.

**Entry**:
- + Buat Baru → Campaign lengkap
- Campaigns → Buat Campaign

**Shortest Path — Wizard 5 langkah**:
1. Identitas: nama campaign, tujuan, logo (smart defaults, auto-detect language)
2. Microsite: pilih template, atur judul/CTA (pre-filled from campaign name)
3. Form: pilih template, atur field (optional skip)
4. Link & QR: auto-generate shortlink (custom alias min 5) + QR with tracking
5. Publish: preview semua aset, test form, publish, download QR, share

**Success**:
- Campaign detail shows Overview + Microsite + Forms + Shortlinks + QR + Media + Analytics + Settings
- All assets linked, unified analytics funnel visible

**Error**:
- Any step validation → inline, can skip non-critical
- Publish fail → rollback draft, show retry

**Help**:
- Progress indicator (Step 2/5)
- Autosave wizard progress
- Review page before publish
- Recommended path vs Advanced path toggle

**Next**:
- Share campaign link
- View unified analytics
- Edit assets

---

## 5. QR Studio

**Goal**: Create QR from any asset.

**Entry**:
- QR Studio → Buat QR
- From shortlink/microsite/form detail → Generate QR
- Campaign wizard auto

**Shortest Path**:
1. Select source (shortlink/microsite/form/campaign/external URL)
2. Pick style (color, logo optional, preset)
3. Download PNG/SVG

**Success**:
- QR preview, scan test
- Tracking enabled if dynamic (v1.1) else static
- Stats: scans

**Error**:
- Invalid source → error
- Logo too large → compress suggestion

**Help**:
- "QR statis = target tetap, QR dinamis = bisa ubah target nanti (Pro)"
- Empty state: "Belum ada QR. Buat dari shortlink atau microsite"

**Next**:
- Download
- Add to microsite block
- View scan analytics

---

## 6. Secure Share Page

**Goal**: Protect microsite/form/campaign with password, idle timeout.

**Entry**:
- Microsite settings → Access → Password protected
- Form settings → Password
- Campaign settings

**Shortest Path**:
1. Toggle Password protected
2. Set password (smart default: generate strong, show copy)
3. Save → session required to view

**Success**:
- Password gate shown when accessing /p/slug or protected resource
- Session server-side, HttpOnly cookie simulated
- Idle timeout 60s default, auto logout, message "Sesi berakhir karena tidak aktif"

**Error**:
- Wrong password 5 times → lockout 60s
- Session expired → redirect to gate
- Access expired date passed → "Akses telah berakhir"

**Help**:
- "Password di-hash Argon2id. Sesi berakhir otomatis setelah 60 detik tidak aktif"
- Revoke all sessions button explanation

**Next**:
- Revoke sessions
- Set expiry
- Set max failed attempts

---

## 7. Analytics Unified

**Goal**: See funnel QR scan → shortlink click → microsite view → form submission

**Entry**:
- Analytics page
- Campaign detail → Analytics tab
- From resource detail → View analytics

**Shortest Path**:
1. Open Analytics or Campaign Analytics
2. See funnel + metrics (views, clicks, unique, QR scans, device, referer, UTM, form starts/completions, completion rate)

**Success**:
- Charts render, daily aggregation
- Privacy note: IP hashed, retention configurable

**Error**:
- No data → empty state with "Belum ada traffic. Bagikan link/QR untuk mulai kumpulkan data"
- Bot filtered note

**Help**:
- "Reach = QR scan + shortlink click, Visit = microsite view, Engagement = button click, Conversion = form submission"

**Next**:
- Export analytics
- Filter by date/UTM
- Drill to resource

---

## 8. Media Library

**Goal**: Upload & reuse assets across microsite/form/QR/campaign.

**Entry**:
- Assets / Media Library
- Microsite builder → Add image → Media library
- Form → File upload field

**Shortest Path**:
1. Upload image (drag-drop)
2. Add alt text (auto-detect optional)
3. Use in microsite

**Success**:
- Asset appears, optimized, reusable

**Error**:
- File too large → limit message
- Private asset URL without token → blocked, signed URL required

**Help**:
- "Asset privat tidak pakai URL permanen tanpa token"

**Next**:
- Organize folder/tag
- Search & reuse

---

## 9. Template Center

**Goal**: Use preset microsite/form/campaign/theme.

**Entry**:
- Templates page
- Quick Create → Dari template
- Campaign wizard step 2/3

**Shortest Path**:
1. Browse category (Event, Education, Library, UMKM, Creator, Portfolio, Survey, Registration, Contact, Complaint)
2. Preview
3. Use template → pre-filled builder

**Success**:
- Builder opens with template structure, no private data copied

**Error**:
- Template not found → fallback to blank

**Help**:
- "Template menyimpan struktur & konfigurasi, bukan respons/data privat"

**Next**:
- Customize & publish
- Save as private template (Pro)

---

## 10. Workspace

**Goal**: Manage members, branding, domain, projects.

**Entry**:
- Workspace page
- Settings → Workspace

**Shortest Path**:
1. Create workspace (auto for personal)
2. Invite member via email (role select)
3. Member accepts (demo: auto)

**Success**:
- Member list, role badges, audit log

**Error**:
- Invite existing → "Sudah anggota"
- Permission denied → "Hanya Owner/Admin dapat undang"

**Help**:
- Role explanation tooltip

**Next**:
- Branding (logo, colors)
- Custom domain (v1.1)
- Billing

---

## Common Patterns

- Empty states: explain what area is for + next best action + sample/template CTA
- Progressive disclosure: important first, advanced hidden until later
- One screen one main action
- Checklist 3-5 items, each leads to correct screen, first pre-completed when sample data chosen
- Contextual help: appears at friction, dismissible, reopenable via help center
- Autosave setup progress
- Review page before setup ends
- No dead empty screen: auto-create first workspace/project/item or offer starter template
