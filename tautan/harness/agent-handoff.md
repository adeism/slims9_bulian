# Agent Handoff — Tautan

## Current State (2026-09-02)
MVP v0.1.0 built as frontend-only SPA in `tautan/` folder inside SLiMS repo. Demo Mode enabled, no password, role picker, sample data. All core modules functional with localStorage.

### What Exists
- harness/ at repo root with 10 docs (product-overview, architecture-map, role-matrix, feature-flows, ui-rules, coding-rules, changelog, agent-handoff, default-settings, feature-flags)
- tautan/ app:
  - index.html SPA shell, responsive, Tailwind CDN, SVG assets
  - core/: config, storage, router, auth, resolver, security, analytics
  - modules/: shortlinks, microsites, forms, qr, campaigns, analytics, workspace, media, templates
  - onboarding/: setup.js single file, checklist, guide
  - tutorials/: quick, detailed, help center
  - demo/: sample data, demo users
  - shared/: components, utils
  - docs/flows/: per role & feature
  - harness/: copy of harness docs for app isolation
  - assets/: logo.svg, bg-pattern.svg

### First Value Flow Working
1. User opens tautan/index.html
2. Setup: mode selection (Demo recommended) → role selection (Personal, Creator, UMKM, Sekolah, Perpustakaan, Panitia, Organisasi, Super Admin) → sample data Yes/No → default settings auto-detect → review → handoff
3. Auto-create workspace, first project if sample data
4. Dashboard shows overview, quick create button, recent, continue-where-you-left-off, checklist 3-5 items
5. User can create shortlink in 3 steps, get link + QR, copy, share
6. Microsite from template, drag-drop blocks, publish
7. Form drag-drop, publish, embed, shortlink & QR, response manager, export CSV
8. QR Studio generate from any asset
9. Analytics funnel
10. Secure page password gate idle timeout 60s

### Feature Flags
- demoMode.enabled = true (easy to disable)
- onboardingExperiment direct-to-task
- customDomain disabled
- fileUpload enabled with kill switch
- aiGenerator disabled
- All kill switches ON
- campaignWizard 100%, analyticsFunnel 100%

### Default Settings
- Language auto-detect, timezone Asia/Jakarta, theme light, date DD/MM/YYYY, currency IDR
- Shortlink slug 5 chars min, 302 redirect, UTM/expiry/password off initially
- Microsite theme clean-light recommended, public, idle timeout 60s enabled, draft
- Form public, idle 60s, limit off, success message default
- QR static, PNG, 512, color #111827, tracking true
- Analytics retention 30 days, IP hash true, bot filtering true
- Security max failed 5, lockout 60s, Argon2id

### Known Issues / TODO
- Backend Laravel not yet implemented, production mode uses localStorage simulation (needs real PHP/MySQL)
- File upload virus scan placeholder
- Email notification placeholder
- Custom domain + SSL not yet
- QR dynamic v1.1 not yet
- Conditional logic form basic placeholder
- API & webhook future
- SLiMS integration future
- AI generator future
- No build step, CDN Tailwind (should be self-hosted for prod)
- Analytics event queue simulated, not real queue worker
- Public routing /{slug} resolver simulation, needs Nginx/Caddy config for real
- Tests not yet automated, manual testing done

### How to Run
- Open `tautan/index.html` directly in browser, or
- `php -S 0.0.0.0:8000 -t tautan` then open https://8000-{sandbox}.e2b.app or http://localhost:8000
- No composer install needed for MVP

### How to Disable Demo Mode
1. Set `demoMode.enabled = false` in `tautan/core/config.js`
2. Remove `tautan/demo/` folder
3. Remove demo imports in `tautan/onboarding/setup.js` and `tautan/core/auth.js`
4. Remove Demo badge in header
5. Test production login

### File Size Rule
All files kept <1000 lines, modular.

### Docs Match App
Yes, flow docs updated.

### Next Steps for Next Agent
1. Implement Laravel backend modular monolith as per architecture-map.md
2. Split setup.js into smaller modules after stable (mode, role, sample, defaults, guide, review)
3. Add automated tests for Definition of Done: shortlink 3 steps, slug <5 rejection, super admin audit, microsite template, form drag-drop + embed, share via shortlink & QR, password page, idle 60s, response export, funnel, draft not public, private data not leak, responsive
4. Implement real QR library with logo support
5. Add media library signed URL, optimization
6. Add analytics daily aggregation worker
7. Add admin center moderation, reserved slugs, audit log UI
8. Add billing placeholder
9. Add SLiMS integration (import anggota, koleksi)
10. Add Ollama AI generator behind feature flag
11. Move feature flags to backend /api/flags
12. Add command palette Ctrl+K
13. Add import templates
14. Add audit trail for settings changes
15. Add sandbox workspace separate from production
16. Security hardening: CSP, security headers, CSRF, rate limit Redis

### Important Rules Reminder
- Fewer steps always better, one screen one main action, one flow one clear goal
- Remove step that doesn't help reach value fast
- Ask only what required for first useful outcome
- Use smart/safe/recommended defaults
- Never blank dead screen, every empty state explain + next action
- Use progressive disclosure
- Keep responsive desktop+mobile
- Use SVG separate lightweight
- Compact clear calm, no fake decorative complexity
- Update docs when app changes
- Feature flags have owner/purpose/creation/expiration/cleanup
- No big bang release risky features, keep rollback simple

### Contact / Owner
- Core team (this agent) — initial MVP
- Next agent: continue from `tautan/` folder, read harness docs first

### Quick Links
- Entry: tautan/index.html
- Config: tautan/core/config.js
- Setup: tautan/onboarding/setup.js
- Sample data: tautan/demo/sample-data.js
- Flows: tautan/docs/flows/ and harness/feature-flows.md
