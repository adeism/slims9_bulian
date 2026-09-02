# Flow: Super Admin

Goal: Buat slug pendek 1-4 char dengan alasan & audit log, kelola reserved slug

Entry: Setup → Super Admin → dashboard → Admin Center

Shortest Path:
1. Shortlinks → Buat Shortlink → input slug 2 char "ab" + reason "promo resmi"
2. System allows with warning, creates audit log
3. Lihat audit log → entry override_slug terlihat

Success: Shortlink 2 char aktif, audit log tercatat actor, action, target, reason

Error: Slug pendek tanpa reason ditolak, reserved slug tetap dilindungi

Help: "Super admin boleh 1-4 char dengan audit log" tooltip

Next: Manage reserved slugs, moderation abuse reports, revoke sessions
