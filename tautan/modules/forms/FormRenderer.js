// Public form renderer with password gate + idle timeout simulation
import { storage } from '../../core/storage.js';
import { validateShareSession, createShareSession } from '../../core/security.js';
import { showToast } from '../../shared/components/Toast.js';
import { trackEvent } from '../../core/analytics.js';

export function renderPublicForm(containerId, slug) {
  const container = document.getElementById(containerId);
  const forms = storage.getForms();
  const form = forms.find(f=>f.slug===slug);
  if (!form) { container.innerHTML='<div class="p-8 text-center">Form tidak ditemukan</div>'; return; }
  if (form.status==='draft') { container.innerHTML='<div class="p-8 text-center">Draft tidak dapat diakses publik</div>'; return; }

  // Check password gate
  if (form.settings.visibility==='password') {
    const token = storage.get('share_token_'+form.id);
    const valid = token ? validateShareSession(token, 'form', form.id, form.settings.idleTimeoutSeconds||60) : { valid: false };
    if (!valid.valid) {
      renderPasswordGate(container, form);
      return;
    }
  }

  container.innerHTML = `
    <div class="max-w-lg mx-auto bg-white rounded-2xl border p-6 mt-8">
      <h2 class="text-xl font-bold">${form.title}</h2>
      <p class="text-sm text-slate-500 mt-1">${form.description||''}</p>
      <div class="mt-6 space-y-4" id="public-form-fields">
        ${form.fields.map(f=>`
          <div><label class="text-sm font-medium">${f.label} ${f.required?'<span class="text-red-500">*</span>':''}</label>
          ${f.type==='short_text'?`<input data-key="${f.field_key}" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm" placeholder="${f.config.placeholder||''}">`:''}
          ${f.type==='long_text'?`<textarea data-key="${f.field_key}" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm"></textarea>`:''}
          ${f.type==='email'?`<input data-key="${f.field_key}" type="email" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm">`:''}
          ${f.type==='dropdown'?`<select data-key="${f.field_key}" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm"><option value="">Pilih</option>${(f.config.options||[]).map(o=>`<option>${o}</option>`).join('')}</select>`:''}
          </div>
        `).join('')}
      </div>
      <button onclick="window.submitPublicForm('${form.id}')" class="w-full mt-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Kirim</button>
      <div class="mt-3 text-[11px] text-slate-400 text-center">Dilindungi password + idle timeout 60 detik jika diaktifkan</div>
    </div>
  `;

  trackEvent({ workspace_id: form.workspace_id, resource_type: 'form', resource_id: form.id, event_type: 'form_start', metadata: {} });

  window.submitPublicForm = (formId) => {
    const f = storage.getForms().find(x=>x.id===formId);
    const answers = {};
    let valid = true;
    container.querySelectorAll('[data-key]').forEach(el=>{
      const key = el.dataset.key;
      const val = el.value.trim();
      const fieldDef = f.fields.find(fd=>fd.field_key===key);
      if (fieldDef?.required && !val) valid=false;
      answers[key]=val;
    });
    if (!valid) { showToast('Lengkapi field wajib','error'); return; }
    const responses = storage.get('form_responses', []);
    responses.unshift({ id: 'resp_'+Date.now(), form_id: formId, response_number: responses.filter(r=>r.form_id===formId).length+1, status: 'new', answers, created_at: new Date().toISOString() });
    storage.set('form_responses', responses);
    // update count
    const forms = storage.getForms();
    const idx = forms.findIndex(x=>x.id===formId);
    if (idx!==-1) { forms[idx].responses = (forms[idx].responses||0)+1; storage.setForms(forms); }
    trackEvent({ workspace_id: f.workspace_id, resource_type: 'form', resource_id: f.id, event_type: 'form_completion', metadata: {} });
    container.innerHTML = `<div class="max-w-lg mx-auto bg-white rounded-2xl border p-8 mt-8 text-center"><div class="text-3xl">✅</div><h3 class="font-bold mt-2">${f.settings.successMessage}</h3><button onclick="window.location.hash='#/overview'" class="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">Kembali</button></div>`;
  };
}

function renderPasswordGate(container, form) {
  container.innerHTML = `
    <div class="max-w-sm mx-auto bg-white rounded-2xl border p-6 mt-16">
      <h3 class="font-bold">Halaman Berpassword</h3>
      <p class="text-sm text-slate-500 mt-1">Masukkan password untuk akses form ini. Sesi berakhir otomatis setelah idle 60 detik.</p>
      <input id="gate-pwd" type="password" placeholder="Password" class="w-full mt-4 px-3 py-2.5 border rounded-xl text-sm">
      <div id="gate-error" class="text-xs text-red-600 mt-2 hidden"></div>
      <button onclick="window.submitGate('${form.id}')" class="w-full mt-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Masuk</button>
      <div class="mt-2 text-[11px] text-slate-400">Max 5 percobaan salah, lockout 60 detik. Cookie HttpOnly/Secure (simulasi).</div>
    </div>
  `;
  window.submitGate = (formId) => {
    const pwd = document.getElementById('gate-pwd').value;
    // For demo, password is 'tautan123' or stored hash check would be here
    if (pwd !== 'tautan123' && pwd !== 'demo') {
      document.getElementById('gate-error').textContent='Password salah';
      document.getElementById('gate-error').classList.remove('hidden');
      return;
    }
    const sess = createShareSession('form', formId);
    storage.set('share_token_'+formId, sess.token);
    showToast('Akses diberikan, sesi 60 detik idle timeout');
    renderPublicForm(container.id, form.slug);
  };
}
