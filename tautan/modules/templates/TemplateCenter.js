import { showToast } from '../../shared/components/Toast.js';

const templates = [
  { id: 'event', category: 'Event', name: 'Seminar / Workshop', type: 'campaign', description: 'Microsite event + form daftar + shortlink + QR poster + dashboard peserta', blocks: ['profile','text','button','form'], recommended: true },
  { id: 'library-service', category: 'Library', name: 'Layanan Perpustakaan', type: 'microsite', description: 'Penjelasan layanan, jam buka, kontak', recommended: true },
  { id: 'book-suggestion', category: 'Library', name: 'Usulan Koleksi', type: 'form', description: 'Form usulan buku dengan alasan', recommended: true },
  { id: 'catalog', category: 'UMKM', name: 'Katalog Produk', type: 'microsite', description: 'Katalog + WA order + QR', recommended: false },
  { id: 'order', category: 'UMKM', name: 'Form Pemesanan', type: 'form', description: 'Order produk UMKM', recommended: false },
  { id: 'portfolio', category: 'Creator', name: 'Portfolio Kreator', type: 'microsite', description: 'Profile + links + karya', recommended: true },
  { id: 'registration', category: 'Education', name: 'Pendaftaran Siswa', type: 'form', description: 'Form registrasi sekolah', recommended: false },
  { id: 'contact', category: 'Contact', name: 'Kontak', type: 'form', description: 'Form kontak sederhana', recommended: false },
  { id: 'complaint', category: 'Complaint', name: 'Pengaduan', type: 'form', description: 'Form pengaduan layanan', recommended: false },
  { id: 'survey', category: 'Survey', name: 'Survey Kepuasan', type: 'form', description: 'Survey rating + komentar', recommended: false }
];

export function renderTemplates(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = `
    <div class="flex justify-between items-center mb-4"><h2 class="text-lg font-bold">Template Center</h2><select id="tmpl-cat" class="px-3 py-2 border rounded-xl text-sm"><option value="all">Semua kategori</option><option>Event</option><option>Education</option><option>Library</option><option>UMKM</option><option>Creator</option><option>Portfolio</option><option>Survey</option><option>Registration</option><option>Contact</option><option>Complaint</option></select></div>
    <div class="grid md:grid-cols-3 gap-3" id="tmpl-grid">
      ${templates.map(t=>`
        <div class="bg-white rounded-2xl border p-4 hover:border-indigo-300 cursor-pointer" onclick="window.useTemplate('${t.id}')">
          <div class="flex justify-between"><span class="text-xs px-2 py-0.5 bg-slate-100 rounded-full">${t.category}</span>${t.recommended?'<span class="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded-full">Direkomendasikan</span>':''}</div>
          <div class="font-medium mt-2">${t.name}</div>
          <div class="text-xs text-slate-500 mt-1">${t.description}</div>
          <div class="mt-2 text-xs"><span class="px-2 py-0.5 bg-indigo-50 text-indigo-600 rounded-full">${t.type}</span></div>
          <button class="mt-3 w-full py-1.5 bg-white border rounded-xl text-xs">Gunakan template</button>
        </div>
      `).join('')}
    </div>
    <div class="mt-4 p-3 bg-slate-50 rounded-xl text-[11px] text-slate-500">Template menyimpan struktur & konfigurasi, tidak menyalin respons/data privat. Kategori: Event, Education, Library, UMKM, Creator, Portfolio, Survey, Registration, Contact, Complaint.</div>
  `;

  window.useTemplate = (id) => {
    const tmpl = templates.find(t=>t.id===id);
    if (!tmpl) return;
    showToast('Template '+tmpl.name+' digunakan, membuka builder...');
    if (tmpl.type==='microsite') window.location.hash='#/microsites/builder';
    else if (tmpl.type==='form') window.location.hash='#/forms/builder';
    else if (tmpl.type==='campaign') window.location.hash='#/campaigns/wizard';
  };

  document.getElementById('tmpl-cat')?.addEventListener('change', (e)=>{
    const cat = e.target.value;
    const grid = document.getElementById('tmpl-grid');
    const filtered = cat==='all'?templates:templates.filter(t=>t.category===cat);
    grid.innerHTML = filtered.map(t=>`
      <div class="bg-white rounded-2xl border p-4 hover:border-indigo-300 cursor-pointer" onclick="window.useTemplate('${t.id}')">
        <div class="flex justify-between"><span class="text-xs px-2 py-0.5 bg-slate-100 rounded-full">${t.category}</span>${t.recommended?'<span class="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded-full">Direkomendasikan</span>':''}</div>
        <div class="font-medium mt-2">${t.name}</div>
        <div class="text-xs text-slate-500 mt-1">${t.description}</div>
        <button class="mt-3 w-full py-1.5 bg-white border rounded-xl text-xs">Gunakan template</button>
      </div>
    `).join('') || '<div class="col-span-3 text-center py-8 text-slate-400 text-sm">Tidak ada template kategori ini</div>';
  });
}
