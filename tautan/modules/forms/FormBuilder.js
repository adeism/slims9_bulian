import { storage, generateId, nowISO, addAuditLog } from '../../core/storage.js';
import { fieldLibrary } from './FieldLibrary.js';
import { validateSlug, checkSlugUnique } from '../../core/security.js';
import { generateSlug } from '../../shared/utils/slug.js';
import { showToast } from '../../shared/components/Toast.js';
import { completeChecklistItem } from '../../onboarding/checklist.js';

export class FormManager {
  static create({ workspace_id, project_id, slug, title, fields = [], settings = {} }) {
    const v = validateSlug(slug, 'user');
    if (!v.valid) return { success: false, reason: v.reason };
    const uniq = checkSlugUnique(slug, workspace_id, null, ['links','microsites','forms']);
    if (!uniq.unique) return { success: false, reason: 'Slug sudah dipakai' };
    const list = storage.getForms();
    const form = { id: generateId(), workspace_id, project_id: project_id||null, slug: slug.toLowerCase(), title, description: '', fields, settings: { visibility: 'public', idleTimeoutEnabled: true, idleTimeoutSeconds: 60, successMessage: 'Terima kasih! Respons Anda telah tercatat.', status: 'draft', ...settings }, responses: 0, status: settings.status||'draft', created_at: nowISO(), updated_at: nowISO() };
    list.unshift(form);
    storage.setForms(list);
    addAuditLog('form.create', `form:${form.id}`, { slug });
    return { success: true, data: form };
  }
  static update(id, patch) {
    const list = storage.getForms();
    const idx = list.findIndex(f=>f.id===id);
    if (idx===-1) return { success: false };
    list[idx] = { ...list[idx], ...patch, updated_at: nowISO() };
    storage.setForms(list);
    addAuditLog('form.update', `form:${id}`, patch);
    return { success: true, data: list[idx] };
  }
  static delete(id) {
    let list = storage.getForms();
    list = list.filter(f=>f.id!==id);
    storage.setForms(list);
    addAuditLog('form.delete', `form:${id}`, {});
    return { success: true };
  }
  static list(wsId) { return storage.getForms().filter(f=>!wsId||f.workspace_id===wsId); }
}

export function renderFormBuilder(containerId, formId = null) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  let form = null;
  if (formId) form = storage.getForms().find(f=>f.id===formId);
  if (!form) {
    const slug = generateSlug('form',5);
    const res = FormManager.create({ workspace_id: wsId, slug, title: 'Form Baru', fields: [{ id: generateId(), field_key: 'name', type: 'short_text', label: 'Nama Lengkap', required: true, sort_order: 1, config: { placeholder: '' } }], settings: { status: 'draft' } });
    if (res.success) form = res.data;
  }

  container.innerHTML = `
    <div class="flex flex-col lg:flex-row gap-4 h-[calc(100vh-120px)]">
      <div class="w-full lg:w-64 bg-white rounded-2xl border p-3 overflow-auto">
        <h3 class="font-semibold text-sm mb-2">Field Library</h3>
        <div class="space-y-2">
          ${fieldLibrary.map(f=>`
            <div draggable="true" ondragstart="window.dragField(event,'${f.type}')" onclick="window.addField('${f.type}')" class="p-2.5 border rounded-xl hover:border-indigo-300 hover:bg-indigo-50 cursor-pointer flex gap-2">
              <span>${f.icon}</span><div><div class="text-sm font-medium">${f.name}</div><div class="text-[11px] text-slate-500">${f.desc}</div></div>
            </div>
          `).join('')}
        </div>
        <div class="mt-3 p-2 bg-slate-50 rounded-xl text-[11px]">Tiga panel: Field Library → Canvas → Properties. Drag field ke canvas. Autosave.</div>
      </div>
      <div class="flex-1 flex flex-col gap-3">
        <div class="bg-white rounded-2xl border p-3 flex justify-between items-center">
          <input id="form-title" value="${form.title}" class="font-bold text-sm px-2 py-1 border rounded-lg">
          <div class="flex gap-2">
            <button onclick="window.previewForm()" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Preview</button>
            <button onclick="window.saveForm()" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Simpan</button>
            <button onclick="window.publishForm()" class="px-4 py-1.5 bg-indigo-600 text-white rounded-xl text-xs">Publish</button>
          </div>
        </div>
        <div id="form-canvas" class="flex-1 bg-white rounded-2xl border p-4 overflow-auto min-h-[300px]" ondrop="window.dropField(event)" ondragover="event.preventDefault()">
          <div id="form-fields" class="max-w-lg mx-auto space-y-3"></div>
        </div>
      </div>
      <div class="w-full lg:w-72 bg-white rounded-2xl border p-3 overflow-auto">
        <h3 class="font-semibold text-sm mb-2">Properties</h3>
        <div id="form-props" class="text-xs text-slate-500">Pilih field untuk edit</div>
        <div class="mt-4 border-t pt-3">
          <h4 class="font-medium text-xs mb-2">Pengaturan Form</h4>
          <label class="text-[11px]">Slug /f/...</label><input id="form-slug" value="${form.slug}" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs font-mono">
          <label class="text-[11px] mt-2 block">Success message</label><textarea id="form-success" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs">${form.settings.successMessage}</textarea>
          <label class="text-[11px] mt-2 block">Visibilitas</label><select id="form-vis" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs"><option value="public" ${form.settings.visibility==='public'?'selected':''}>Public</option><option value="password" ${form.settings.visibility==='password'?'selected':''}>Password Protected</option><option value="members" ${form.settings.visibility==='members'?'selected':''}>Members only</option></select>
          <div class="mt-2 flex flex-col gap-1 text-[11px]"><label class="flex items-center gap-1"><input type="checkbox" id="form-limit" ${form.settings.limitResponseEnabled?'checked':''}> Limit response</label><label class="flex items-center gap-1"><input type="checkbox" id="form-one-email" ${form.settings.oneResponsePerEmail?'checked':''}> One per email</label></div>
          <div class="mt-3 flex gap-2"><button onclick="window.deleteForm('${form.id}')" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-xl text-xs">Hapus</button><button onclick="window.duplicateForm('${form.id}')" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Duplikat</button></div>
          <div class="mt-3 p-2 bg-indigo-50 rounded-xl text-[11px]">Form dapat di-embed ke microsite, generate shortlink & QR, export CSV/XLSX/JSON.</div>
        </div>
      </div>
    </div>
  `;

  let fields = [...form.fields];
  let selectedIdx = -1;

  function renderFields() {
    const wrap = document.getElementById('form-fields');
    if (!wrap) return;
    wrap.innerHTML = fields.map((f,i)=>`
      <div onclick="window.selectField(${i})" class="p-3 border rounded-xl ${selectedIdx===i?'border-indigo-600 bg-indigo-50':'border-slate-200 bg-slate-50'} cursor-pointer relative group">
        <div class="flex justify-between"><span class="text-[11px] text-slate-400">${f.type} • ${f.required?'required':''}</span><span class="text-[11px]">#${i+1}</span></div>
        <div class="font-medium text-sm mt-1">${f.label}</div>
        <div class="text-xs text-slate-500">${f.config?.placeholder||f.type}</div>
        <div class="absolute top-1 right-1 hidden group-hover:flex gap-1"><button onclick="event.stopPropagation(); window.moveField(${i},-1)" class="w-6 h-6 bg-white border rounded text-[10px]">↑</button><button onclick="event.stopPropagation(); window.moveField(${i},1)" class="w-6 h-6 bg-white border rounded text-[10px]">↓</button><button onclick="event.stopPropagation(); window.removeField(${i})" class="w-6 h-6 bg-red-50 text-red-600 rounded text-[10px]">✕</button><button onclick="event.stopPropagation(); window.duplicateField(${i})" class="w-6 h-6 bg-white border rounded text-[10px]">⎘</button></div>
      </div>
    `).join('') || '<div class="text-center py-12 text-slate-400 text-sm">Canvas kosong. Drag field pertama dari kiri.</div>';
  }

  window.dragField = (e,type) => { e.dataTransfer.setData('text/plain', type); };
  window.dropField = (e) => { e.preventDefault(); const t = e.dataTransfer.getData('text/plain'); if(t) window.addField(t); };
  window.addField = (type) => {
    const lib = fieldLibrary.find(f=>f.type===type);
    if (!lib) return;
    const newField = { id: generateId(), field_key: type+'_'+Math.random().toString(36).slice(2,5), type, label: lib.defaultConfig.label, required: lib.defaultConfig.required||false, sort_order: fields.length+1, config: { ...lib.defaultConfig } };
    fields.push(newField);
    renderFields();
    // autosave
    FormManager.update(form.id, { fields });
    showToast('Field ditambahkan');
  };
  window.selectField = (i) => {
    selectedIdx = i;
    renderFields();
    const f = fields[i];
    const props = document.getElementById('form-props');
    props.innerHTML = `
      <label class="text-[11px]">Label</label><input id="prop-label" value="${f.label}" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs">
      <label class="text-[11px] mt-2 block">Required</label><input type="checkbox" id="prop-req" ${f.required?'checked':''}>
      <label class="text-[11px] mt-2 block">Placeholder</label><input id="prop-ph" value="${f.config?.placeholder||''}" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs">
      ${f.config?.options?`<label class="text-[11px] mt-2 block">Options (comma separated)</label><input id="prop-opts" value="${f.config.options.join(',')}" class="w-full mt-1 px-2 py-1.5 border rounded-lg text-xs">`:''}
      <button onclick="window.updateField()" class="mt-3 px-3 py-1.5 bg-indigo-600 text-white rounded-xl text-xs">Update</button>
      <div class="mt-2 text-[10px] text-slate-400">Keyboard: Tab + Enter untuk tambah field. Undo/redo planned.</div>
    `;
  };
  window.updateField = () => {
    const f = fields[selectedIdx];
    if (!f) return;
    f.label = document.getElementById('prop-label').value;
    f.required = document.getElementById('prop-req').checked;
    if (document.getElementById('prop-ph')) f.config.placeholder = document.getElementById('prop-ph').value;
    if (document.getElementById('prop-opts')) f.config.options = document.getElementById('prop-opts').value.split(',').map(s=>s.trim()).filter(Boolean);
    renderFields();
    FormManager.update(form.id, { fields });
    showToast('Field diupdate (autosave)');
  };
  window.removeField = (i) => { fields.splice(i,1); selectedIdx=-1; renderFields(); FormManager.update(form.id, { fields }); };
  window.moveField = (i,dir) => { const j=i+dir; if(j<0||j>=fields.length) return; [fields[i],fields[j]]=[fields[j],fields[i]]; selectedIdx=j; renderFields(); FormManager.update(form.id, { fields }); };
  window.duplicateField = (i) => { const f = { ...fields[i], id: generateId(), field_key: fields[i].field_key+'_copy', label: fields[i].label+' Copy' }; fields.splice(i+1,0,f); renderFields(); FormManager.update(form.id, { fields }); };
  window.saveForm = () => {
    const title = document.getElementById('form-title').value;
    const slug = document.getElementById('form-slug').value;
    const success = document.getElementById('form-success').value;
    const vis = document.getElementById('form-vis').value;
    FormManager.update(form.id, { title, slug: slug.toLowerCase(), fields, settings: { ...form.settings, successMessage: success, visibility: vis, status: 'draft' } });
    showToast('Form disimpan (draft)');
  };
  window.publishForm = () => {
    const title = document.getElementById('form-title').value;
    const slug = document.getElementById('form-slug').value;
    const success = document.getElementById('form-success').value;
    const vis = document.getElementById('form-vis').value;
    FormManager.update(form.id, { title, slug: slug.toLowerCase(), fields, settings: { ...form.settings, successMessage: success, visibility: vis, status: 'published' }, status: 'published' });
    showToast('Form publish! Live di /f/'+slug);
    completeChecklistItem('create_form');
  };
  window.previewForm = () => { showToast('Preview form (simulasi)'); };
  window.deleteForm = (id) => { if(confirm('Hapus form?')){ FormManager.delete(id); showToast('Dihapus'); window.location.hash='#/forms'; } };
  window.duplicateForm = (id) => {
    const orig = storage.getForms().find(f=>f.id===id);
    if (!orig) return;
    const newSlug = orig.slug + '-' + Math.random().toString(36).slice(2,4);
    FormManager.create({ workspace_id: orig.workspace_id, slug: newSlug, title: orig.title+' Copy', fields: orig.fields, settings: { ...orig.settings, status: 'draft' } });
    showToast('Duplikat dibuat');
    window.location.hash='#/forms';
  };

  renderFields();
}

export function renderFormsList(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  const list = FormManager.list(wsId);
  if (list.length===0) {
    import('../../shared/components/EmptyState.js').then(m=>{
      container.innerHTML = m.renderEmptyState({ icon: '📋', title: 'Belum ada Form', desc: 'Form Maker drag-drop: Field Library → Canvas → Properties. Buat form pendaftaran, usulan koleksi, dll.', ctaText: '+ Buat Form', ctaAction: "window.location.hash='#/forms/builder'", secondaryText: 'Lihat template', secondaryAction: "window.location.hash='#/templates'" });
    });
    return;
  }
  container.innerHTML = `
    <div class="flex justify-between items-center mb-4"><h2 class="text-lg font-bold">Forms (${list.length})</h2><button onclick="window.location.hash='#/forms/builder'" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">+ Buat Form</button></div>
    <div class="grid md:grid-cols-2 gap-3">
      ${list.map(f=>`
        <div class="bg-white rounded-2xl border p-4">
          <div class="flex justify-between"><span class="font-mono text-xs text-indigo-600">/f/${f.slug}</span><span class="text-xs px-2 py-0.5 rounded-full ${f.status==='published'?'bg-green-50 text-green-700':'bg-amber-50 text-amber-700'}">${f.status}</span></div>
          <div class="font-medium mt-1">${f.title}</div>
          <div class="text-xs text-slate-500">${f.fields.length} field • ${f.responses||0} respons • ${f.settings.visibility}</div>
          <div class="mt-3 flex flex-wrap gap-2"><button onclick="window.location.hash='#/forms/builder/${f.id}'" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Edit</button><button onclick="window.viewResponses('${f.id}')" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Respons</button><button onclick="window.exportForm('${f.id}')" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Export CSV</button><button onclick="window.shareForm('${f.id}')" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Share QR</button></div>
        </div>
      `).join('')}
    </div>
  `;
  window.viewResponses = (id) => { window.location.hash = '#/forms/responses/'+id; };
  window.exportForm = (id) => {
    const responses = storage.get('form_responses', []).filter(r=>r.form_id===id);
    const csv = 'data:text/csv;charset=utf-8,' + 'id,response_number,status,answers\\n' + responses.map(r=>`${r.id},${r.response_number},${r.status},"${JSON.stringify(r.answers).replace(/"/g,'""')}"`).join('\\n');
    const link = document.createElement('a'); link.setAttribute('href', encodeURI(csv)); link.setAttribute('download', 'responses_'+id+'.csv'); document.body.appendChild(link); link.click(); document.body.removeChild(link);
    showToast('Export CSV dimulai');
  };
  window.shareForm = (id) => { window.location.hash='#/qr'; localStorage.setItem('tautan_qr_source', JSON.stringify({type:'form',id})); };
}
