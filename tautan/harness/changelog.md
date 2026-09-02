# Changelog — Tautan

## 2026-09-02 — Initial MVP Build (v0.1.0)

### Added
- Created harness/ folder with 10 docs: product-overview, architecture-map, role-matrix, feature-flows, ui-rules, coding-rules, changelog, agent-handoff, default-settings, feature-flags
- Created tautan/ app structure: core/, modules/, onboarding/, tutorials/, demo/, shared/, docs/, harness/, assets/
- Implemented core/:
  - config.js: feature flags, default settings, auto-detect, smart defaults per role
  - storage.js: localStorage abstraction, workspace, projects, links, microsites, forms, qr, analytics, media, templates, reserved_slugs, audit_logs
  - router.js: hash router, public resolver Strategy A prefix (/m/, /f/, /qr/, /p/)
  - auth.js: Demo Mode no password role picker, Production Mode password, session_version, idle timeout 60s
  - resolver.js: unified resource resolver, slug policy (user 5-50, super admin 1-50 with audit), reserved slug protection, safe browsing anti-SSRF
  - security.js: password hash simulation, session validation, rate limit, lockout, cache-control
  - analytics.js: event queue non-blocking, daily aggregation, funnel
- Implemented modules/:
  - shortlinks: CRUD, slug validation, UTM builder, expiry, password, pause, QR from shortlink, bulk CSV planned, audit log for super admin short slug
  - microsites: block library (Profile, Text, Button, Image, Video, Social, Form, QR, Divider, Product, Announcement), drag-drop, theme preset, draft/publish, SEO metadata, favicon, custom slug, public/password, idle timeout, expiry, revoke sessions, preview mobile/desktop
  - forms: field library (Short text, Long text, Email, Phone, Number, Date/time, Dropdown, Radio, Checkbox, Yes/No, File upload, Rating, Consent, Hidden), drag-drop 3 panels, properties, validation, conditional logic placeholder, password protection, limit response, one per email, success message/redirect, email notification placeholder, response manager, export CSV/XLSX/JSON, embed, shortlink & QR
  - qr: static MVP, PNG/SVG, color/logo, preset, UTM tracking, download/duplicate, scan stats
  - campaigns: wizard 5 steps (identitas, microsite, form, link & QR, publish), auto-generate assets, unified analytics
  - analytics: unified dashboard funnel Reach→Visit→Engagement→Conversion→Outcome, metrics views/clicks/unique/QR scans/device/OS/browser/referer/country/city/UTM/form starts/completions/completion rate, privacy IP hash
  - workspace: members, role matrix, branding, projects
  - media: upload, folder/tag, search, optimization, alt text, size limit, signed URL simulation, virus scan placeholder
  - templates: category Event/Education/Library/UMKM/Creator/Portfolio/Survey/Registration/Contact/Complaint, definition without private data
- Implemented onboarding/:
  - setup.js single file handling mode selection, role selection, sample data choice, default settings, guide start, first success handoff, progress indicator, autosave, review page, skip non-critical, smart defaults, auto-detect
  - checklist.js 3-5 items, direct to screen, first pre-completed when sample data
  - guide.js direct-to-task, short interactive, dismissible, reopenable
- Implemented tutorials/: quick, detailed, help center always available, contextual help per screen, beginner hint, reset onboarding in Settings
- Implemented demo/: sample-data.js generates sample data per role, demo-users.js 5 sample users, isolated easy to remove
- Implemented shared/: components Button, Modal, Toast, EmptyState, HelpBubble; utils slug, validators, svg
- Implemented assets/: logo.svg, bg-pattern.svg handmade lightweight
- Implemented docs/flows/: per role and per feature flow docs with goal, entry, shortest path, success, error, help, next action
- Implemented index.html SPA shell: responsive, compact clear calm, Tailwind CDN, one screen one main action, dense useful info not overwhelming, progressive disclosure, empty states with next action, command palette placeholder, continue-where-you-left-off, recent actions, one-click start
- Implemented first value: user enters → one real task → clear result fast → configure later. Auto-create first workspace/project/item, no dead empty screen.
- Security: safe browsing check, block localhost/private IP/metadata, Argon2id simulation, HttpOnly/Secure cookie simulation, session regeneration, revoke version, server-side timeout validation, private no-store, content not sent before session valid, draft not public, private data not leak via HTML/cache/asset/API, audit log, abuse report placeholder, rate limit, CSP placeholder
- UI: responsive desktop/mobile, SVG backgrounds separate, compact clear calm, no fake decorative complexity, best hierarchy/spacing/grouping/visual focus
- Code: modular, each file <1000 lines, separate logic/config/UI/demo/onboarding/docs, demo isolated easy to remove

### Changed
- N/A initial

### Fixed
- N/A initial

### Security
- Implemented slug policy, reserved slug, safe browsing, password hash, idle timeout, session version, rate limit

### Docs
- All harness docs match app

## Next Planned
- Split setup.js into smaller modules after stable
- Implement Laravel backend for production mode
- Add custom domain + SSL
- QR dynamic
- File upload virus scan
- Email notification
- API & webhook
- SLiMS integration
- AI generator with Ollama
