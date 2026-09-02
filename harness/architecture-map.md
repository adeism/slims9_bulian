# Architecture Map — Tautan

## Overview Diagram
```
Browser
  ↓
tautan/index.html (SPA shell)
  ├── core/ (config, storage, router, auth, resolver, security)
  ├── modules/ (shortlinks, microsites, forms, qr, campaigns, analytics, workspace, media, templates)
  ├── onboarding/ (setup.js single file handling mode/role/sample/defaults/guide/handoff)
  ├── tutorials/ (quick, detailed, help center)
  ├── demo/ (sample data, demo users)
  ├── shared/ (components, utils)
  └── assets/ (SVG backgrounds)

Data Layer:
Demo Mode: localStorage (tautan_* keys)
Production Mode: API → Laravel modular monolith → MySQL + Redis + Object Storage (planned)

Public Routing (MVP Strategy A prefix):
/{slug} → shortlink resolver
/m/{slug} → microsite
/f/{slug} → form page
/embed/f/{slug} → embedded form
/qr/{id} → QR asset
/p/{slug} → secure page (alias for password gate)
```

## Folder Responsibilities
- core/: permanent logic, no demo code. Config, storage abstraction, router, auth, security, analytics event bus.
- modules/: each feature self-contained, <1000 lines per file. UI + logic separated.
- onboarding/: first-run flow. setup.js is single file until defaults stable, then split.
- tutorials/: onboarding help, contextual, dismissible, reopenable.
- demo/: isolated demo data, easy to remove. No import in core/.
- shared/: reusable UI, validators, slug utils, SVG helpers.
- docs/: flow docs per role & feature, always match app.
- harness/: agent handoff docs.
- assets/: SVG separate files, lightweight.

## Data Model (MVP localStorage, future SQL)
```
workspaces: id, owner_id, name, type, branding, settings
workspace_members: workspace_id, user_id, role
projects (campaigns): id, workspace_id, name, status, starts_at, ends_at
links: id, workspace_id, project_id, slug, target_url, settings (utm, expiry, password, redirect 301/302, pause)
microsites: id, workspace_id, project_id, slug, title, blocks[], settings (theme, seo, favicon, public/password, idle_timeout, expiry)
forms: id, workspace_id, project_id, slug, title, settings
form_fields: id, form_id, field_key, type, config, logic, sort_order
form_responses: id, form_id, response_number, status, metadata, answers
qr_codes: id, workspace_id, project_id, resource_type, resource_id, style, tracking
media_assets: id, workspace_id, owner_id, path, mime, size, visibility
analytics_events: id, workspace_id, project_id, resource_type, resource_id, event_type, metadata, timestamp
analytics_daily: aggregated metrics per day
share_sessions: token, resource_type, resource_id, session_version, last_activity_at
templates: id, owner_id, workspace_id, category, definition, visibility
reserved_slugs: slug, scope, reason, is_active
audit_logs: actor_id, workspace_id, action, target, metadata, created_at
```

## Security Boundaries
- Password hashing: simulated bcrypt in demo, real Argon2id in prod.
- Server-side session check simulated via session_version.
- Idle timeout: 60s default, server-side validation, client timer as UX.
- No private content sent before session valid.
- Cache-Control: private, no-store for secure pages.
- Safe browsing: block localhost, loopback, private IP, metadata endpoint in URL validation.
- Slug policy: user 5-50 chars a-z0-9-_, super admin 1-50 with audit log + reason.

## Modular Monolith Future
```
Nginx/Caddy
  ↓
Laravel app
  ├── Dashboard web (Inertia/Vue or Livewire)
  ├── Public renderer
  ├── Redirect handler (high perf, cached)
  ├── Form submission API
  └── Admin center
        ↓
MySQL 8 + Redis + Queue + Object Storage
```
Redirect & analytics worker separable later.

## Resolver Strategy
MVP uses Strategy A (prefix) to avoid conflicts:
- /abcde → shortlink
- /m/abcde → microsite
- /f/abcde → form
Alias registry table `public_aliases` prepared for v1.1 to allow branded slugs.

## Build & Run
- No build step for MVP: index.html + ES modules via CDN.
- Run: `php -S 0.0.0.0:8000 -t tautan` or open index.html directly.
- Production: Laravel setup planned, Docker available from parent SLiMS.

## File Size Rule
- Keep each file <1000 lines, smaller better.
- Separate logic/config/UI/demo/onboarding/docs.
- Demo code isolated, easy to delete: only in demo/ and flagged in core/config.js.

## Performance
- Redirect handler must be non-blocking logging via queue.
- Analytics daily aggregation via queue worker.
- Image optimization, compression, signed URL for private assets (future).
