# Flow: Personal User

Goal: Buat shortlink pertama dalam 3 langkah, dapat link aktif + QR

Entry: Setup → pilih Personal role (Budi) → sample data Yes → dashboard

Shortest Path:
1. Dashboard → + Buat Baru → Shortlink
2. Input target URL (https://example.com) + slug otomatis 5 char
3. Klik Buat → success link aktif + QR + copy

Success: Link aktif, toast "Shortlink aktif! Siap dibagikan", QR preview, checklist item done

Error: Slug <5 ditolak, invalid URL localhost/private IP, reserved slug, slug taken suggest alternatives

Help: Bubble "Slug adalah nama pendek di belakang domain. Contoh: tautan.id/promo-literasi" dismissible

Next: Generate QR, add to campaign, set expiry/password, view analytics, copy & share
