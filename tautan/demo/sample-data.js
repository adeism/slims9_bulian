import { storage, generateId, nowISO } from '../core/storage.js';
import { generateSlug } from '../shared/utils/slug.js';

export function generateSampleData(user, workspaceId) {
  const wsId = workspaceId;
  const now = nowISO();
  // Campaigns
  const campaigns = [
    { id: generateId(), workspace_id: wsId, name: 'Seminar Literasi Digital 2026', slug: 'seminar-literasi-2026', description: 'Campaign seminar perpustakaan', status: 'active', starts_at: now, ends_at: null, created_at: now },
    { id: generateId(), workspace_id: wsId, name: 'Promo Kopi Gayo', slug: 'promo-kopi', description: 'Promo UMKM', status: 'active', starts_at: now, ends_at: null, created_at: now }
  ];
  // Shortlinks
  const links = [
    { id: generateId(), workspace_id: wsId, project_id: campaigns[0].id, slug: generateSlug('daftar-literasi',5), target_url: 'https://example.com/f/daftar-seminar', title: 'Link Daftar Seminar', clicks: 42, settings: { redirectType: 302, utm: { source: 'poster', medium: 'qr', campaign: 'literasi' } }, status: 'active', created_at: now },
    { id: generateId(), workspace_id: wsId, project_id: campaigns[1].id, slug: generateSlug('kopi-gayo',5), target_url: 'https://example.com/m/kopi-gayo', title: 'Katalog Kopi', clicks: 128, settings: { redirectType: 302 }, status: 'active', created_at: now },
    { id: generateId(), workspace_id: wsId, project_id: null, slug: 's.id', target_url: 'https://slims.web.id', title: 'SLiMS', clicks: 10, settings: {}, status: 'active', created_at: now }
  ];
  // Microsites
  const microsites = [
    { id: generateId(), workspace_id: wsId, project_id: campaigns[0].id, slug: 'seminar-literasi', title: 'Seminar Literasi Digital 2026', blocks: [{ type: 'profile', data: { name: 'Perpustakaan Kota', bio: 'Mendorong literasi digital untuk semua', avatar: '📚' }},{ type: 'text', data: { content: 'Bergabunglah di seminar literasi digital terbesar tahun ini. Gratis!' }},{ type: 'button', data: { label: 'Daftar Sekarang', url: '/f/daftar-seminar', style: 'primary' }},{ type: 'divider' }], settings: { theme: 'library-warm', visibility: 'public', idleTimeoutEnabled: true, idleTimeoutSeconds: 60, status: 'published' }, views: 320, status: 'published', created_at: now },
    { id: generateId(), workspace_id: wsId, project_id: campaigns[1].id, slug: 'kopi-gayo', title: 'Kopi Gayo Premium', blocks: [{ type: 'profile', data: { name: 'Warung Kopi Budi', bio: 'Kopi asli Gayo, langsung dari petani' }},{ type: 'product', data: { name: 'Kopi Gayo 250gr', price: 'Rp 45.000', image: '☕' }},{ type: 'button', data: { label: 'Pesan via WA', url: 'https://wa.me/628123456789', style: 'primary' }}], settings: { theme: 'umkm-fresh', visibility: 'public', status: 'published' }, views: 210, status: 'published', created_at: now }
  ];
  // Forms
  const forms = [
    { id: generateId(), workspace_id: wsId, project_id: campaigns[0].id, slug: 'daftar-seminar', title: 'Form Pendaftaran Seminar', description: 'Daftar seminar literasi', fields: [{ id: generateId(), field_key: 'name', type: 'short_text', label: 'Nama Lengkap', required: true, sort_order: 1 },{ id: generateId(), field_key: 'email', type: 'email', label: 'Email', required: true, sort_order: 2 },{ id: generateId(), field_key: 'phone', type: 'phone', label: 'No HP', required: false, sort_order: 3 },{ id: generateId(), field_key: 'institution', type: 'dropdown', label: 'Institusi', options: ['Sekolah','Kampus','Perpustakaan','Umum'], sort_order: 4 }], settings: { visibility: 'public', successMessage: 'Terima kasih! Anda terdaftar.', status: 'published' }, responses: 24, status: 'published', created_at: now },
    { id: generateId(), workspace_id: wsId, project_id: null, slug: 'usulan-buku', title: 'Usulan Koleksi Buku', description: 'Usulkan buku untuk perpustakaan', fields: [{ id: generateId(), field_key: 'book_title', type: 'short_text', label: 'Judul Buku', required: true, sort_order: 1 },{ id: generateId(), field_key: 'author', type: 'short_text', label: 'Pengarang', required: false, sort_order: 2 },{ id: generateId(), field_key: 'reason', type: 'long_text', label: 'Alasan', required: true, sort_order: 3 }], settings: { visibility: 'public', status: 'published' }, responses: 12, status: 'published', created_at: now }
  ];
  // QR
  const qrCodes = [
    { id: generateId(), workspace_id: wsId, project_id: campaigns[0].id, resource_type: 'link', resource_id: links[0].id, slug: links[0].slug, style: { color: '#111827', bg: '#fff', logo: null }, scans: 56, created_at: now },
    { id: generateId(), workspace_id: wsId, project_id: campaigns[1].id, resource_type: 'microsite', resource_id: microsites[1].id, slug: microsites[1].slug, style: { color: '#064e3b', bg: '#ecfdf5' }, scans: 89, created_at: now }
  ];
  // Responses
  const responses = [
    { id: generateId(), form_id: forms[0].id, response_number: 1, status: 'new', answers: { name: 'Andi', email: 'andi@example.com', phone: '0812xxxx', institution: 'Sekolah' }, created_at: now },
    { id: generateId(), form_id: forms[0].id, response_number: 2, status: 'new', answers: { name: 'Siti', email: 'siti@example.com', phone: '0813xxxx', institution: 'Kampus' }, created_at: now }
  ];
  // Analytics events sample
  const events = [];
  for(let i=0;i<50;i++) {
    events.push({ id: generateId(), workspace_id: wsId, project_id: campaigns[0].id, resource_type: 'link', resource_id: links[0].id, event_type: ['click','view','form_completion'][Math.floor(Math.random()*3)], metadata: { device: ['mobile','desktop'][Math.floor(Math.random()*2)], browser: 'Chrome', os: 'Android', ip_hash: 'hash'+i, timestamp: new Date(Date.now()-Math.random()*86400000*7).toISOString() }, created_at: new Date(Date.now()-Math.random()*86400000*7).toISOString() });
  }

  // Save
  storage.setCampaigns(campaigns);
  storage.setLinks(links);
  storage.setMicrosites(microsites);
  storage.setForms(forms);
  storage.set('form_responses', responses);
  storage.setQrCodes(qrCodes);
  storage.setAnalyticsEvents(events);

  return { campaigns, links, microsites, forms, qrCodes, responses };
}

export function clearSampleData() {
  storage.setCampaigns([]);
  storage.setLinks([]);
  storage.setMicrosites([]);
  storage.setForms([]);
  storage.set('form_responses', []);
  storage.setQrCodes([]);
  storage.setAnalyticsEvents([]);
}
