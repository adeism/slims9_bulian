import { storage, generateId, nowISO, addAuditLog } from '../../core/storage.js';
import { generateSlug } from '../../shared/utils/slug.js';
import { showToast } from '../../shared/components/Toast.js';
import { ShortlinkManager } from '../shortlinks/ShortlinkManager.js';
import { MicrositeManager } from '../microsites/MicrositeBuilder.js';
import { FormManager } from '../forms/FormBuilder.js';
import { QRManager } from '../qr/QRStudio.js';

export class CampaignManager {
  static create({ workspace_id, name, description, status='draft' }) {
    const list = storage.getCampaigns();
    const camp = { id: generateId(), workspace_id, name, slug: generateSlug(name,5), description, status, starts_at: nowISO(), ends_at: null, created_at: nowISO() };
    list.unshift(camp);
    storage.setCampaigns(list);
    addAuditLog('campaign.create', `campaign:${camp.id}`, { name });
    return { success: true, data: camp };
  }
  static list(wsId) { return storage.getCampaigns().filter(c=>!wsId||c.workspace_id===wsId); }
  static delete(id) { let list=storage.getCampaigns(); list=list.filter(c=>c.id!==id); storage.setCampaigns(list); return {success:true}; }
}

export function renderCampaignWizard(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  let state = { step:1, total:5, name: '', desc: '', logo: null, micrositeTemplate: 'event', micrositeTitle: '', formTemplate: 'event-registration', formTitle: '', shortlinkSlug: generateSlug('campaign',5), qrStyle: { color: '#111827' } };

  function render() {
    container.innerHTML = `
      <div class="max-w-3xl mx-auto bg-white rounded-2xl border overflow-hidden">
        <div class="px-6 py-4 border-b flex justify-between items-center"><h2 class="font-bold">Campaign Wizard - Langkah ${state.step}/${state.total}</h2><div class="w-32 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-indigo-600" style="width:${state.step/state.total*100}%"></div></div></div>
        <div class="p-6" id="wizard-content"></div>
      </div>
    `;
    const content = document.getElementById('wizard-content');
    if (state.step===1) renderStep1(content);
    else if (state.step===2) renderStep2(content);
    else if (state.step===3) renderStep3(content);
    else if (state.step===4) renderStep4(content);
    else if (state.step===5) renderStep5(content);
  }

  function renderStep1(content) {
    content.innerHTML = `
      <h3 class="font-semibold">Langkah 1 - Identitas Campaign</h3>
      <div class="mt-4 space-y-3">
        <div><label class="text-xs">Nama campaign *</label><input id="camp-name" value="${state.name}" placeholder="Seminar Literasi Digital 2026" class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm"></div>
        <div><label class="text-xs">Deskripsi</label><textarea id="camp-desc" class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm" placeholder="Deskripsi singkat">${state.desc}</textarea></div>
        <div><label class="text-xs">Logo (opsional)</label><input type="file" class="w-full mt-1 text-sm"></div>
        <div class="p-3 bg-indigo-50 rounded-xl text-xs">Workspace: ${storage.get('current_workspace_id')?.slice(0,8)} • Periode aktif bisa diatur nanti</div>
      </div>
      <div class="mt-6 flex justify-end"><button onclick="window.wizNext1()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Lanjut →</button></div>
    `;
    window.wizNext1 = () => { state.name=document.getElementById('camp-name').value; state.desc=document.getElementById('camp-desc').value; if(!state.name){ showToast('Nama wajib','error'); return; } state.step=2; render(); };
  }
  function renderStep2(content) {
    content.innerHTML = `
      <h3 class="font-semibold">Langkah 2 - Microsite</h3>
      <p class="text-xs text-slate-500 mt-1">Pilih template, atur judul, CTA, gambar. Tambahkan blok form nanti.</p>
      <div class="mt-4 grid grid-cols-2 gap-3">
        ${['event','library-service','catalog','profile-links','school-info'].map(t=>`<div onclick="window.pickMsTemplate('${t}')" class="p-3 border rounded-xl cursor-pointer ${state.micrositeTemplate===t?'border-indigo-600 bg-indigo-50':'hover:border-slate-300'}"><div class="font-medium text-sm">${t}</div><div class="text-xs text-slate-500">Template ${t}</div></div>`).join('')}
      </div>
      <div class="mt-4"><label class="text-xs">Judul microsite</label><input id="ms-title" value="${state.micrositeTitle||state.name}" class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm"></div>
      <div class="mt-6 flex justify-between"><button onclick="window.wizPrev()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">← Kembali</button><div class="flex gap-2"><button onclick="window.wizSkip()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">Lewati</button><button onclick="window.wizNext2()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Lanjut →</button></div></div>
    `;
    window.pickMsTemplate = (t)=>{ state.micrositeTemplate=t; render(); };
    window.wizPrev = ()=>{ state.step--; render(); };
    window.wizSkip = ()=>{ state.step=3; render(); };
    window.wizNext2 = ()=>{ state.micrositeTitle=document.getElementById('ms-title').value; state.step=3; render(); };
  }
  function renderStep3(content) {
    content.innerHTML = `
      <h3 class="font-semibold">Langkah 3 - Form</h3>
      <p class="text-xs text-slate-500 mt-1">Pilih template form, atur field, validasi, pesan sukses, notifikasi.</p>
      <div class="mt-4 grid grid-cols-2 gap-3">
        ${['event-registration','book-suggestion','contact','order','member-registration'].map(t=>`<div onclick="window.pickFormTemplate('${t}')" class="p-3 border rounded-xl cursor-pointer ${state.formTemplate===t?'border-indigo-600 bg-indigo-50':'hover:border-slate-300'}"><div class="font-medium text-sm">${t}</div><div class="text-xs text-slate-500">Template ${t}</div></div>`).join('')}
      </div>
      <div class="mt-4"><label class="text-xs">Judul form</label><input id="form-title" value="${state.formTitle||'Form '+state.name}" class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm"></div>
      <div class="mt-6 flex justify-between"><button onclick="window.wizPrev()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">← Kembali</button><div class="flex gap-2"><button onclick="window.wizSkip()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">Lewati</button><button onclick="window.wizNext3()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Lanjut →</button></div></div>
    `;
    window.pickFormTemplate = (t)=>{ state.formTemplate=t; render(); };
    window.wizNext3 = ()=>{ state.formTitle=document.getElementById('form-title').value; state.step=4; render(); };
  }
  function renderStep4(content) {
    content.innerHTML = `
      <h3 class="font-semibold">Langkah 4 - Link & QR</h3>
      <p class="text-xs text-slate-500 mt-1">Generate shortlink & QR otomatis, pilih custom alias minimal 5 karakter, pilih source tracking.</p>
      <div class="mt-4 space-y-3">
        <div><label class="text-xs">Custom alias (min 5)</label><div class="flex gap-2 mt-1"><span class="px-3 py-2.5 bg-slate-50 border rounded-xl text-sm">tautan.id/</span><input id="sl-alias" value="${state.shortlinkSlug}" class="flex-1 px-3 py-2.5 border rounded-xl text-sm font-mono"></div></div>
        <div><label class="text-xs">UTM Source</label><input id="utm-source" placeholder="poster" class="w-full mt-1 px-3 py-2.5 border rounded-xl text-sm"></div>
        <div class="p-3 bg-slate-50 rounded-xl text-xs">QR akan digenerate otomatis dengan tracking. Warna & logo bisa diatur nanti di QR Studio.</div>
      </div>
      <div class="mt-6 flex justify-between"><button onclick="window.wizPrev()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">← Kembali</button><button onclick="window.wizNext4()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Lanjut →</button></div>
    `;
    window.wizNext4 = ()=>{ state.shortlinkSlug=document.getElementById('sl-alias').value; state.utmSource=document.getElementById('utm-source').value; state.step=5; render(); };
  }
  function renderStep5(content) {
    content.innerHTML = `
      <h3 class="font-semibold">Langkah 5 - Publish</h3>
      <p class="text-xs text-slate-500 mt-1">Preview semua aset, test form, publish microsite & form, download QR, bagikan link.</p>
      <div class="mt-4 bg-slate-50 rounded-xl p-4 space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-slate-500">Campaign</span><span class="font-medium">${state.name}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Microsite</span><span class="font-medium">${state.micrositeTemplate} - ${state.micrositeTitle}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Form</span><span class="font-medium">${state.formTemplate} - ${state.formTitle}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Shortlink</span><span class="font-mono text-indigo-600">tautan.id/${state.shortlinkSlug}</span></div>
        <div class="flex justify-between"><span class="text-slate-500">UTM</span><span class="font-medium">${state.utmSource||'-'}</span></div>
      </div>
      <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-xl text-xs text-green-800">✅ Semua aset akan dibuat & terhubung. Dashboard statistik gabungan akan aktif.</div>
      <div class="mt-6 flex justify-between"><button onclick="window.wizPrev()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">← Kembali</button><button onclick="window.wizPublish()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Publish Campaign 🚀</button></div>
    `;
    window.wizPublish = () => {
      const wsId = storage.get('current_workspace_id');
      // Create campaign
      const campRes = CampaignManager.create({ workspace_id: wsId, name: state.name, description: state.desc, status: 'active' });
      const campId = campRes.data.id;
      // Microsite
      MicrositeManager.create({ workspace_id: wsId, project_id: campId, slug: generateSlug(state.micrositeTitle||state.name,5), title: state.micrositeTitle||state.name, blocks: [{ type: 'profile', data: { name: state.name, bio: state.desc } },{ type: 'text', data: { content: 'Selamat datang di '+state.name } },{ type: 'button', data: { label: 'Daftar', url: '/f/'+state.formTitle, style: 'primary' } }], settings: { theme: 'event-bold', status: 'published' } });
      // Form
      FormManager.create({ workspace_id: wsId, project_id: campId, slug: generateSlug(state.formTitle||'daftar',5), title: state.formTitle||'Form Pendaftaran', fields: [{ id: generateId(), field_key: 'name', type: 'short_text', label: 'Nama', required: true, sort_order:1, config:{} }], settings: { status: 'published' } });
      // Shortlink
      ShortlinkManager.create({ workspace_id: wsId, project_id: campId, slug: state.shortlinkSlug, target_url: 'https://tautan.id/m/'+generateSlug(state.name,5), title: state.name, settings: { utm: { source: state.utmSource } } });
      // QR
      QRManager.create({ workspace_id: wsId, project_id: campId, resource_type: 'campaign', resource_id: campId, style: { color: '#111827' } });
      showToast('Campaign publish! Semua aset terhubung');
      window.location.hash = '#/campaigns';
    };
  }

  render();
}

export function renderCampaignsList(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  const list = CampaignManager.list(wsId);
  if (list.length===0) {
    import('../../shared/components/EmptyState.js').then(m=>{
      container.innerHTML = m.renderEmptyState({ icon: '📁', title: 'Belum ada Campaign', desc: 'Campaign adalah wadah gabungan microsite + form + shortlink + QR + analytics. Buat campaign lengkap dalam 5 langkah.', ctaText: '+ Buat Campaign Lengkap', ctaAction: "window.location.hash='#/campaigns/wizard'", secondaryText: 'Lihat template', secondaryAction: "window.location.hash='#/templates'" });
    });
    return;
  }
  container.innerHTML = `
    <div class="flex justify-between items-center mb-4"><h2 class="text-lg font-bold">Campaigns (${list.length})</h2><button onclick="window.location.hash='#/campaigns/wizard'" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">+ Campaign Lengkap</button></div>
    <div class="grid md:grid-cols-2 gap-3">
      ${list.map(c=>`
        <div class="bg-white rounded-2xl border p-4">
          <div class="flex justify-between"><span class="font-mono text-xs text-indigo-600">${c.slug}</span><span class="text-xs px-2 py-0.5 bg-green-50 text-green-700 rounded-full">${c.status}</span></div>
          <div class="font-medium mt-1">${c.name}</div>
          <div class="text-xs text-slate-500">${c.description||''}</div>
          <div class="mt-3 flex gap-2"><button onclick="window.openCampaign('${c.id}')" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Detail</button><button onclick="window.deleteCampaign('${c.id}')" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-xl text-xs">Hapus</button></div>
        </div>
      `).join('')}
    </div>
  `;
  window.openCampaign = (id) => { window.location.hash='#/campaigns/'+id; };
  window.deleteCampaign = (id) => { if(confirm('Hapus campaign?')){ CampaignManager.delete(id); renderCampaignsList(containerId); } };
}
