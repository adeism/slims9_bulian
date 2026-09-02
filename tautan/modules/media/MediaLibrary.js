import { storage, generateId, nowISO } from '../../core/storage.js';
import { showToast } from '../../shared/components/Toast.js';

export function renderMediaLibrary(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  const assets = storage.getMedia().filter(m=>!wsId||m.workspace_id===wsId);

  container.innerHTML = `
    <div class="flex justify-between items-center mb-4"><h2 class="text-lg font-bold">Media Library (${assets.length})</h2><label class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm cursor-pointer">+ Upload<input type="file" id="media-upload" class="hidden" multiple accept="image/*,.pdf"></label></div>
    <div class="bg-white rounded-2xl border p-4">
      <div class="flex gap-2 mb-4"><input placeholder="Search..." class="flex-1 px-3 py-2 border rounded-xl text-sm"><select class="px-3 py-2 border rounded-xl text-sm"><option>Semua</option><option>Gambar</option><option>Dokumen</option></select></div>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        ${assets.map(a=>`
          <div class="border rounded-xl p-3">
            <div class="w-full h-24 bg-slate-50 rounded-lg flex items-center justify-center text-2xl">${a.mime?.includes('image')?'🖼️':'📄'}</div>
            <div class="text-xs font-medium mt-2 truncate">${a.path}</div>
            <div class="text-[11px] text-slate-500">${(a.size/1024).toFixed(1)} KB • ${a.visibility}</div>
            <div class="mt-2 flex gap-1"><button class="px-2 py-1 bg-white border rounded text-[11px]">Copy URL</button><button class="px-2 py-1 bg-red-50 text-red-600 rounded text-[11px]">Hapus</button></div>
          </div>
        `).join('') || '<div class="col-span-4 text-center py-12 text-slate-400 text-sm">Belum ada asset. Upload gambar/dokumen. Satu media library untuk microsite, form, QR, campaign. Signed URL untuk privat.</div>'}
      </div>
      <div class="mt-4 text-[11px] text-slate-400">Aturan penting: asset privat tidak boleh pakai URL public permanen tanpa token/permission check. Optimasi gambar, kompresi, alt text, virus scan (future).</div>
    </div>
  `;

  document.getElementById('media-upload')?.addEventListener('change', (e)=>{
    const files = Array.from(e.target.files);
    const list = storage.getMedia();
    files.forEach(f=>{
      if (f.size > 5*1024*1024) { showToast('File terlalu besar max 5MB','error'); return; }
      list.unshift({ id: generateId(), workspace_id: wsId, owner_id: storage.getCurrentUser()?.id, path: f.name, mime: f.type, size: f.size, visibility: 'public', alt: '', created_at: nowISO() });
    });
    storage.setMedia(list);
    showToast('Upload berhasil');
    renderMediaLibrary(containerId);
  });
}
