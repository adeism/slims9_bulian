# Flow: Shortlink

Goal, Entry, Shortest Path, Success, Error, Help, Next - documented in harness/feature-flows.md

This file mirrors that doc for per-feature requirement.

Goal: Create shortlink redirect with analytics

Entry: + Buat Baru → Shortlink, Shortlinks page, from microsite/form detail, campaign wizard step 4

Shortest Path: 3 steps - click create → input target + optional custom slug min 5 → Buat → success

Success: Active link, short URL, target, clicks 0, QR preview, share, analytics link, toast

Error: Slug <5 for normal user, slug taken suggest 3 alternatives, invalid URL (localhost/private IP/metadata), reserved slug, empty URL

Help: Contextual bubble slug explanation, beginner hint on empty state

Next: Generate QR, add to campaign, expiry/password, analytics, copy share
