# Role Matrix — Tautan

## Role Definitions

### Personal Roles (app-level)
- **User (Personal)**: owns personal workspace, creates/manages own resources. Can create shortlink min 5 chars, microsite, form, QR, view own analytics.
- **Super Admin**: manages all system, slug override 1-4 chars with reason + audit log, manages reserved slugs, moderation, abuse reports, audit logs, system settings.

### Workspace Roles
- **Owner**: billing, members, all resources, delete workspace, manage branding/domain.
- **Admin**: workspace settings, all resources CRUD + publish, manage members (except owner), view analytics.
- **Editor**: create/edit/publish resources per policy, view analytics allowed.
- **Contributor**: create draft, edit own drafts, cannot publish.
- **Viewer**: view dashboard & analytics allowed, no edit.

## Permission Granular
```
link.create, link.update, link.publish, link.delete, link.view
microsite.create, microsite.update, microsite.publish, microsite.delete
form.create, form.update, form.publish, form.view_responses, form.export_responses, form.delete_response
qr.create, qr.update, qr.delete
analytics.view, analytics.export
workspace.manage_members, workspace.manage_branding, workspace.manage_billing
admin.override_slug, admin.manage_reserved_slugs, admin.moderate, admin.view_audit
template.create_private, template.manage_org
campaign.create, campaign.manage
media.upload, media.manage
```

## Matrix

| Permission | Personal User | Super Admin | Owner | Admin | Editor | Contributor | Viewer |
|---|---|---|---|---|---|---|---|
| link.create | Y | Y | Y | Y | Y | draft | N |
| link.publish | Y | Y | Y | Y | Y | N | N |
| microsite.create | Y | Y | Y | Y | Y | draft | N |
| microsite.publish | Y | Y | Y | Y | Y | N | N |
| form.create | Y | Y | Y | Y | Y | draft | N |
| form.view_responses | own | all | Y | Y | Y | own | limited |
| form.export | own | all | Y | Y | Y | N | N |
| qr.create | Y | Y | Y | Y | Y | Y | N |
| analytics.view | own | all | Y | Y | Y | own | allowed |
| workspace.manage_members | N | Y | Y | Y | N | N | N |
| admin.override_slug | N | Y (audit) | N | N | N | N | N |
| admin.manage_reserved | N | Y | N | N | N | N | N |

## Demo Mode Users
Demo Mode picks sample user without password:
- Demo Personal: Budi (creator UMKM)
- Demo Librarian: Sari (perpustakaan)
- Demo Event Organizer: Panitia Literasi
- Demo Teacher: Pak Andi (sekolah)
- Demo Super Admin: Admin Tautan (can test 1-4 char slug)

Each demo user has pre-filled workspace + sample data if user opts "Yes sample data".

## First Value Moment per Role
- Personal: Create shortlink → get active link + QR in 30 sec
- Creator: Microsite from template → publish → share link
- UMKM: Catalog microsite + order form + QR
- Librarian: Collection suggestion form + shortlink + CSV export
- Event: Full campaign wizard 5 steps → landing + form + shortlink + QR + analytics
- Super Admin: Create reserved slug list, test short slug with reason, see audit log
- Owner: Invite member, see permission work
- Viewer: See dashboard, understand funnel

## Entry Points
- All: Setup file → mode → role → sample data → dashboard
- Quick Create button on dashboard routes to shortest path per intent
- Continue-where-you-left-off: last edited resource card on overview
- Recent actions list
- Command palette (Ctrl+K) for feature-rich navigation (future)

## Security per Role
- Contributor cannot publish → draft not accessible public (resolver checks status)
- Viewer cannot see private responses unless allowed
- Super admin slug override requires reason + audit log
- All password-protected resources check session server-side (simulated) + idle timeout 60s
