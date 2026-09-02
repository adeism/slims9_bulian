import { ShortlinkManager } from './ShortlinkManager.js';
import { storage } from '../../core/storage.js';
import { generateSlug } from '../../shared/utils/slug.js';
import { showToast } from '../../shared/components/Toast.js';
import { openModal, closeModal } from '../../shared/components/Modal.js';
import { renderEmptyState } from '../../shared/components/EmptyState.js';
import { completeChecklistItem } from '../../onboarding/checklist.js';
import { trackEvent } from '../../core/analytics.js';

export function renderShortlinks(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  const links = ShortlinkManager.list(wsId);
  if (links.length===0) {
    container.innerHTML = renderEmptyState({
      icon: '🔗',
      title: 'Belum ada Shortlink',
      desc: 'Shortlink membantu bagikan link panjang jadi pendek & terlacak. Buat pertama dalam 3 langkah.',
      ctaText: '+ Buat Shortlink pertama',
      ctaAction: "window.openCreateShortlink()",
      secondaryText: 'Lihat template',
      secondaryAction: "window.location.hash='#/templates'"
    });
  } else {
    container.innerHTML = `
      <div class="flex justify-between items-center mb-4"><h2 class="text-lg font-bold">Shortlinks (${links.length})</h2><button onclick="window.openCreateShortlink()" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">+ Buat Shortlink</button></div>
      <div class="grid gap-3">
        ${links.map(l=>`
          <div class="bg-white rounded-2xl border p-4 flex gap-4 items-start">
            <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center">🔗</div>
            <div class="flex-1">
              <div class="flex items-center gap-2"><span class="font-mono text-sm font-semibold text-indigo-600">tautan.id/${l.slug}</span><span class="text-xs px-2 py-0.5 bg-slate-100 rounded-full">${l.settings.redirectType||302}</span><span class="text-xs px-2 py-0.5 ${l.status==='active'?'bg-green-50 text-green-700':'bg-amber-50 text-amber-700'} rounded-full">${l.status}</span></div>
              <div class="text-sm text-slate-900 mt-1">${l.title}</div>
              <div class="text-xs text-slate-500 truncate">${l.target_url}</div>
              <div class="mt-2 flex gap-2 text-xs"><span class="px-2 py-1 bg-slate-50 rounded-full">${l.clicks||0} klik</span><button onclick="window.copyLink('https://tautan.id/${l.slug}')" class="px-2 py-1 bg-white border rounded-full">Copy</button><button onclick="window.viewAnalytics('${l.id}')" class="px-2 py-1 bg-white border rounded-full">Analytics</button><button onclick="window.generateQR('${l.id}','link')" class="px-2 py-1 bg-white border rounded-full">QR</button><button onclick="window.deleteLink('${l.id}')" class="px-2 py-1 bg-red-50 text-red-600 rounded-full">Hapus</button></div>
            </div>
          </div>
        `).join('')}
      </div>
    `;
  }

  window.openCreateShortlink = () => {
    const defaultSlug = generateSlug('',5);
    openModal({
      title: 'Buat Shortlink (3 langkah)',
      content: `
        <div class="space-y-4">
          <div><label class="text-xs text-slate-600">URL Tujuan *</label><input id="sl-target" placeholder="https://example.com/halaman" class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm"><div class="text-[11px] text-slate-400 mt-1">Akan dicek safe browsing: block localhost/private IP/metadata</div></div>
          <div><label class="text-xs text-slate-600">Slug custom (minimal 5 karakter) <span class="text-[11px] bg-indigo-50 text-indigo-600 px-1 rounded">Direkomendasikan otomatis</span></label><div class="flex gap-2 mt-1"><span class="px-3 py-2.5 bg-slate-50 border rounded-xl text-sm">tautan.id/</span><input id="sl-slug" value="${defaultSlug}" class="flex-1 px-3 py-2.5 border rounded-xl text-sm font-mono"></div><div id="sl-error" class="text-xs text-red-600 mt-1 hidden"></div></div>
          <div><label class="text-xs text-slate-600">Judul (opsional)</label><input id="sl-title" placeholder="Promo Seminar" class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm"></div>
          <div class="p-3 bg-slate-50 rounded-xl text-xs"><div class="font-medium">Pengaturan lanjutan (bisa nanti):</div><div class="mt-1 flex gap-2"><label class="flex items-center gap-1"><input type="checkbox" id="sl-utm"> UTM</label><label class="flex items-center gap-1"><input type="checkbox" id="sl-expiry"> Expiry</label><label class="flex items-center gap-1"><input type="checkbox" id="sl-pwd"> Password</label></div></div>
          <div class="flex justify-end gap-2"><button onclick="window.closeModal()" class="px-4 py-2 bg-white border rounded-xl text-sm">Batal</button><button onclick="window.submitShortlink()" class="px-5 py-2 bg-indigo-600 text-white rounded-xl text-sm">Buat Shortlink</button></div>
        </div>
      `
    });
    window.closeModal = closeModal;
    window.submitShortlink = () => {
      const target = document.getElementById('sl-target').value.trim();
      const slug = document.getElementById('sl-slug').value.trim();
      const title = document.getElementById('sl-title').value.trim();
      const user = storage.getCurrentUser();
      const wsId = storage.get('current_workspace_id');
      if (!target) { document.getElementById('sl-error').textContent='URL wajib'; document.getElementById('sl-error').classList.remove('hidden'); return; }
      const res = ShortlinkManager.create({ workspace_id: wsId, slug, target_url: target, title, userRole: user?.is_superadmin?'superadmin':'user', reason: user?.is_superadmin?'demo superadmin':'', settings: {} });
      if (!res.success) {
        const errEl = document.getElementById('sl-error');
        errEl.textContent = res.reason + (res.suggestions? ' Saran: '+res.suggestions.join(', ') : '');
        errEl.classList.remove('hidden');
        showToast(res.reason,'error');
        return;
      }
      closeModal();
      showToast('Shortlink aktif! Siap dibagikan');
      completeChecklistItem('create_shortlink');
      trackEvent({ workspace_id: wsId, resource_type: 'link', resource_id: res.data.id, event_type: 'click', metadata: {} });
      renderShortlinks(containerId);
    };
  };
  window.copyLink = (url) => { navigator.clipboard.writeText(url); showToast('Disalin: '+url); };
  window.deleteLink = (id) => { if(confirm('Hapus shortlink?')){ ShortlinkManager.delete(id); renderShortlinks(containerId); showToast('Dihapus'); } };
  window.viewAnalytics = (id) => { window.location.hash = '#/analytics'; };
  window.generateQR = (id,type) => { window.location.hash = '#/qr'; localStorage.setItem('tautan_qr_source', JSON.stringify({type,id})); };
}
