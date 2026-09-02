import { storage, generateId, nowISO, addAuditLog } from '../../core/storage.js';
import { blockLibrary } from './BlockLibrary.js';
import { validateSlug, checkSlugUnique } from '../../core/security.js';
import { generateSlug } from '../../shared/utils/slug.js';
import { showToast } from '../../shared/components/Toast.js';
import { completeChecklistItem } from '../../onboarding/checklist.js';

export class MicrositeManager {
  static create({ workspace_id, project_id, slug, title, blocks = [], settings = {} }) {
    const v = validateSlug(slug, 'user');
    if (!v.valid) return { success: false, reason: v.reason };
    const uniq = checkSlugUnique(slug, workspace_id, null, ['links','microsites','forms']);
    if (!uniq.unique) return { success: false, reason: 'Slug sudah dipakai' };
    const list = storage.getMicrosites();
    const ms = { id: generateId(), workspace_id, project_id: project_id||null, slug: slug.toLowerCase(), title, blocks, settings: { theme: 'clean-light', visibility: 'public', idleTimeoutEnabled: true, idleTimeoutSeconds: 60, status: 'draft', ...settings }, views: 0, status: settings.status||'draft', created_at: nowISO(), updated_at: nowISO() };
    list.unshift(ms);
    storage.setMicrosites(list);
    addAuditLog('microsite.create', `microsite:${ms.id}`, { slug });
    return { success: true, data: ms };
  }
  static update(id, patch) {
    const list = storage.getMicrosites();
    const idx = list.findIndex(m=>m.id===id);
    if (idx===-1) return { success: false };
    list[idx] = { ...list[idx], ...patch, updated_at: nowISO() };
    storage.setMicrosites(list);
    addAuditLog('microsite.update', `microsite:${id}`, patch);
    return { success: true, data: list[idx] };
  }
  static delete(id) {
    let list = storage.getMicrosites();
    list = list.filter(m=>m.id!==id);
    storage.setMicrosites(list);
    addAuditLog('microsite.delete', `microsite:${id}`, {});
    return { success: true };
  }
  static list(wsId) { return storage.getMicrosites().filter(m=>!wsId||m.workspace_id===wsId); }
}

export function renderMicrositeBuilder(containerId, micrositeId = null) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  let ms = null;
  if (micrositeId) ms = storage.getMicrosites().find(m=>m.id===micrositeId);
  if (!ms) {
    // create draft auto for first value
    const slug = generateSlug('microsite',5);
    const res = MicrositeManager.create({ workspace_id: wsId, slug, title: 'Microsite Baru', blocks: [{ type: 'profile', data: { name: 'Nama', bio: 'Bio singkat' } }], settings: { status: 'draft' } });
    if (res.success) ms = res.data;
  }

  container.innerHTML = `
    <div class="flex flex-col lg:flex-row gap-4 h-[calc(100vh-120px)]">
      <div class="w-full lg:w-64 bg-white rounded-2xl border p-3 overflow-auto">
        <h3 class="font-semibold text-sm mb-2">Block Library</h3>
        <div class="space-y-2">
          ${blockLibrary.map(b=>`
            <div draggable="true" ondragstart="window.dragBlock(event,'${b.type}')" onclick="window.addBlock('${b.type}')" class="p-2.5 border rounded-xl hover:border-indigo-300 hover:bg-indigo-50 cursor-pointer flex gap-2">
              <span>${b.icon}</span><div><div class="text-sm font-medium">${b.name}</div><div class="text-[11px] text-slate-500">${b.desc}</div></div>
            </div>
          `).join('')}
        </div>
        <div class="mt-4 p-2 bg-amber-50 rounded-xl text-[11px]">💡 Drag blok ke kanvas atau klik untuk tambah. Mobile: tap untuk tambah.</div>
      </div>
      <div class="flex-1 flex flex-col gap-3">
        <div class="bg-white rounded-2xl border p-3 flex justify-between items-center">
          <input id="ms-title" value="${ms.title}" class="font-bold text-sm px-2 py-1 border rounded-lg">
          <div class="flex gap-2">
            <button onclick="window.previewToggle()" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Preview Mobile/Desktop</button>
            <button onclick="window.saveMicrosite()" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Simpan</button>
            <button onclick="window.publishMicrosite()" class="px-4 py-1.5 bg-indigo-600 text-white rounded-xl text-xs">Publish</button>
          </div>
        </div>
        <div id="ms-canvas" class="flex-1 bg-white rounded-2xl border p-4 overflow-auto min-h-[300px]" ondrop="window.dropBlock(event)" ondragover="event.preventDefault()">
          <div id="ms-blocks" class="max-w-md mx-auto space-y-3"></div>
        </div>
      </div>
      <div class="w-full lg:w-72 bg-white rounded-2xl border p-3">
        <h3 class="font-semibold text-sm mb-2">Properties</h3>
        <div id="ms-props" class="text-xs text-slate-500">Pilih blok untuk edit</div>
        <div class="mt-4 border-t pt-3">
          <h4 class="font-medium text-xs mb-2">Pengaturan Halaman</h4>
          <label class="text-[11px]">Slug (tautan.id/m/...)</label><input id="ms-slug" value="${ms.slug}" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs font-mono">
          <label class="text-[11px] mt-2 block">Tema <span class="bg-green-100 text-green-700 px-1 rounded">Direkomendasikan: clean-light</span></label><select id="ms-theme" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs"><option value="clean-light">Bersih Terang</option><option value="dark-mode">Gelap</option><option value="library-warm">Perpustakaan Hangat</option><option value="umkm-fresh">UMKM Segar</option><option value="event-bold">Event Berani</option></select>
          <label class="text-[11px] mt-2 block">Visibilitas</label><select id="ms-vis" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs"><option value="public" ${ms.settings.visibility==='public'?'selected':''}>Public</option><option value="password" ${ms.settings.visibility==='password'?'selected':''}>Password Protected</option></select>
          <div id="ms-pwd-wrap" class="${ms.settings.visibility==='password'?'':'hidden'} mt-2"><input id="ms-pwd" placeholder="Password" type="password" class="w-full px-2 py-1.5 border rounded-lg text-xs"><div class="text-[10px] text-slate-400 mt-1">Idle timeout 60 detik default, server-side validation</div></div>
          <div class="mt-3 flex gap-2"><button onclick="window.deleteMicrosite('${ms.id}')" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-xl text-xs">Hapus</button><button onclick="window.duplicateMicrosite('${ms.id}')" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Duplikat</button></div>
        </div>
      </div>
    </div>
  `;

  let blocks = [...ms.blocks];
  let selectedIdx = -1;
  let previewMode = 'mobile';

  function renderBlocks() {
    const wrap = document.getElementById('ms-blocks');
    if (!wrap) return;
    wrap.innerHTML = blocks.map((b,i)=>`
      <div onclick="window.selectBlock(${i})" class="p-3 border rounded-xl ${selectedIdx===i?'border-indigo-600 bg-indigo-50':'border-slate-200 bg-slate-50'} cursor-pointer relative group">
        <div class="text-[11px] text-slate-400">${b.type}</div>
        <div class="mt-1">${renderBlockPreview(b)}</div>
        <div class="absolute top-1 right-1 hidden group-hover:flex gap-1"><button onclick="event.stopPropagation(); window.moveBlock(${i},-1)" class="w-6 h-6 bg-white border rounded text-[10px]">↑</button><button onclick="event.stopPropagation(); window.moveBlock(${i},1)" class="w-6 h-6 bg-white border rounded text-[10px]">↓</button><button onclick="event.stopPropagation(); window.removeBlock(${i})" class="w-6 h-6 bg-red-50 text-red-600 rounded text-[10px]">✕</button></div>
      </div>
    `).join('') || '<div class="text-center py-12 text-slate-400 text-sm">Canvas kosong. Drag blok pertama dari kiri.</div>';
    wrap.parentElement.className = `flex-1 bg-white rounded-2xl border p-4 overflow-auto ${previewMode==='mobile'?'':'lg'}`;
    if (previewMode==='mobile') wrap.className='max-w-md mx-auto space-y-3';
    else wrap.className='max-w-2xl mx-auto space-y-3';
  }

  function renderBlockPreview(b) {
    if (b.type==='profile') return `<div class="flex gap-2"><div class="w-10 h-10 bg-white rounded-full flex items-center justify-center">${b.data.avatar||'👤'}</div><div><div class="font-medium text-sm">${b.data.name}</div><div class="text-xs text-slate-500">${b.data.bio}</div></div></div>`;
    if (b.type==='text') return `<div class="text-sm">${b.data.content}</div>`;
    if (b.type==='button') return `<button class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">${b.data.label}</button>`;
    if (b.type==='divider') return `<hr class="my-2">`;
    if (b.type==='product') return `<div class="flex gap-2 items-center"><div class="text-2xl">${b.data.image}</div><div><div class="font-medium text-sm">${b.data.name}</div><div class="text-xs">${b.data.price}</div></div></div>`;
    if (b.type==='announcement') return `<div class="p-2 bg-amber-50 border border-amber-200 rounded-xl text-sm">📢 ${b.data.text}</div>`;
    return `<div class="text-sm">${b.type} block</div>`;
  }

  window.dragBlock = (e,type) => { e.dataTransfer.setData('text/plain', type); };
  window.dropBlock = (e) => { e.preventDefault(); const type = e.dataTransfer.getData('text/plain'); if(type) window.addBlock(type); };
  window.addBlock = (type) => {
    const lib = blockLibrary.find(b=>b.type===type);
    if (!lib) return;
    blocks.push({ type, data: { ...lib.defaultData }, id: generateId() });
    renderBlocks();
    showToast('Blok ditambahkan');
  };
  window.selectBlock = (i) => {
    selectedIdx = i;
    renderBlocks();
    const b = blocks[i];
    const props = document.getElementById('ms-props');
    if (!props) return;
    if (b.type==='profile') props.innerHTML = `<label class="text-[11px]">Nama</label><input id="prop-name" value="${b.data.name}" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs"><label class="text-[11px] mt-2 block">Bio</label><textarea id="prop-bio" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs">${b.data.bio}</textarea><button onclick="window.updateBlock()" class="mt-2 px-3 py-1.5 bg-indigo-600 text-white rounded-xl text-xs">Update</button>`;
    else if (b.type==='text') props.innerHTML = `<label class="text-[11px]">Content</label><textarea id="prop-content" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs h-24">${b.data.content}</textarea><button onclick="window.updateBlock()" class="mt-2 px-3 py-1.5 bg-indigo-600 text-white rounded-xl text-xs">Update</button>`;
    else if (b.type==='button') props.innerHTML = `<label class="text-[11px]">Label</label><input id="prop-label" value="${b.data.label}" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs"><label class="text-[11px] mt-2 block">URL</label><input id="prop-url" value="${b.data.url}" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs"><button onclick="window.updateBlock()" class="mt-2 px-3 py-1.5 bg-indigo-600 text-white rounded-xl text-xs">Update</button>`;
    else props.innerHTML = `<div class="text-xs">Edit untuk ${b.type} belum lengkap (MVP). Data: <pre class="bg-slate-50 p-2 rounded mt-1 text-[10px]">${JSON.stringify(b.data,null,2)}</pre></div>`;
  };
  window.updateBlock = () => {
    const b = blocks[selectedIdx];
    if (!b) return;
    if (b.type==='profile') { b.data.name = document.getElementById('prop-name').value; b.data.bio = document.getElementById('prop-bio').value; }
    if (b.type==='text') b.data.content = document.getElementById('prop-content').value;
    if (b.type==='button') { b.data.label = document.getElementById('prop-label').value; b.data.url = document.getElementById('prop-url').value; }
    renderBlocks();
    showToast('Blok diupdate');
  };
  window.removeBlock = (i) => { blocks.splice(i,1); selectedIdx=-1; renderBlocks(); };
  window.moveBlock = (i,dir) => { const j=i+dir; if(j<0||j>=blocks.length) return; [blocks[i],blocks[j]]=[blocks[j],blocks[i]]; selectedIdx=j; renderBlocks(); };
  window.previewToggle = () => { previewMode = previewMode==='mobile'?'desktop':'mobile'; renderBlocks(); showToast('Preview: '+previewMode); };
  window.saveMicrosite = () => {
    const title = document.getElementById('ms-title').value;
    const slug = document.getElementById('ms-slug').value;
    const theme = document.getElementById('ms-theme').value;
    const vis = document.getElementById('ms-vis').value;
    MicrositeManager.update(ms.id, { title, slug: slug.toLowerCase(), blocks, settings: { ...ms.settings, theme, visibility: vis, status: 'draft' } });
    showToast('Microsite disimpan (draft)');
  };
  window.publishMicrosite = () => {
    const title = document.getElementById('ms-title').value;
    const slug = document.getElementById('ms-slug').value;
    const theme = document.getElementById('ms-theme').value;
    const vis = document.getElementById('ms-vis').value;
    const res = MicrositeManager.update(ms.id, { title, slug: slug.toLowerCase(), blocks, settings: { ...ms.settings, theme, visibility: vis, status: 'published' }, status: 'published' });
    showToast('Microsite publish! Live di /m/'+slug);
    completeChecklistItem('create_microsite');
  };
  window.deleteMicrosite = (id) => { if(confirm('Hapus microsite?')){ MicrositeManager.delete(id); showToast('Dihapus'); window.location.hash='#/microsites'; } };
  window.duplicateMicrosite = (id) => {
    const orig = storage.getMicrosites().find(m=>m.id===id);
    if (!orig) return;
    const newSlug = orig.slug + '-' + Math.random().toString(36).slice(2,4);
    MicrositeManager.create({ workspace_id: orig.workspace_id, slug: newSlug, title: orig.title+' Copy', blocks: orig.blocks, settings: { ...orig.settings, status: 'draft' } });
    showToast('Duplikat dibuat');
    window.location.hash='#/microsites';
  };

  document.getElementById('ms-vis')?.addEventListener('change', (e)=>{
    const wrap = document.getElementById('ms-pwd-wrap');
    if (e.target.value==='password') wrap.classList.remove('hidden'); else wrap.classList.add('hidden');
  });

  renderBlocks();
}

export function renderMicrositesList(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  const list = MicrositeManager.list(wsId);
  if (list.length===0) {
    import('../../shared/components/EmptyState.js').then(m=>{
      container.innerHTML = m.renderEmptyState({ icon: '🌐', title: 'Belum ada Microsite', desc: 'Microsite adalah halaman blok drag-drop. Mulai dari template atau kosong.', ctaText: '+ Buat Microsite', ctaAction: "window.location.hash='#/microsites/builder'", secondaryText: 'Lihat template', secondaryAction: "window.location.hash='#/templates'" });
    });
    return;
  }
  container.innerHTML = `
    <div class="flex justify-between items-center mb-4"><h2 class="text-lg font-bold">Microsites (${list.length})</h2><button onclick="window.location.hash='#/microsites/builder'" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">+ Buat Microsite</button></div>
    <div class="grid md:grid-cols-2 gap-3">
      ${list.map(m=>`
        <div class="bg-white rounded-2xl border p-4">
          <div class="flex justify-between"><span class="font-mono text-xs text-indigo-600">/m/${m.slug}</span><span class="text-xs px-2 py-0.5 rounded-full ${m.status==='published'?'bg-green-50 text-green-700':'bg-amber-50 text-amber-700'}">${m.status}</span></div>
          <div class="font-medium mt-1">${m.title}</div>
          <div class="text-xs text-slate-500">${m.blocks.length} blok • ${m.views||0} views • ${m.settings.theme}</div>
          <div class="mt-3 flex gap-2"><button onclick="window.location.hash='#/microsites/builder/${m.id}'" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Edit</button><button onclick="window.openPreview('/m/${m.slug}')" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Preview</button><button onclick="window.deleteMs('${m.id}')" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-xl text-xs">Hapus</button></div>
        </div>
      `).join('')}
    </div>
  `;
  window.openPreview = (path) => { showToast('Preview: '+path+' (simulasi)'); };
  window.deleteMs = (id) => { if(confirm('Hapus?')){ MicrositeManager.delete(id); renderMicrositesList(containerId); } };
}
