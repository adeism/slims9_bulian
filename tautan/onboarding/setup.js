// One setup file handling: mode selection, role selection, sample data choice, default settings, guide start, first success handoff
import { storage, generateId, nowISO } from '../core/storage.js';
import { defaultSettings, roleTemplates, featureFlags, appConfig } from '../core/config.js';
import { demoUsers } from '../demo/demo-users.js';
import { generateSampleData, clearSampleData } from '../demo/sample-data.js';
import { showToast } from '../shared/components/Toast.js';

let state = {
  step: 1,
  totalSteps: 5,
  mode: 'demo', // demo | production
  selectedRole: null,
  selectedUser: null,
  sampleData: true,
  settings: { ...defaultSettings },
  workspaceName: '',
  progress: 0
};

function loadProgress() {
  const saved = storage.getSetupProgress();
  if (saved) {
    state = { ...state, ...saved };
    // if completed, skip
    if (saved.completed) return true;
  }
  return false;
}

function saveProgress() {
  state.progress = Math.round((state.step / state.totalSteps) * 100);
  storage.setSetupProgress({ ...state, updated_at: nowISO() });
}

export function initSetup(containerId, onComplete) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const completed = loadProgress();
  if (completed && storage.getCurrentUser()) {
    onComplete && onComplete();
    return;
  }
  render(container, onComplete);
}

function render(container, onComplete) {
  saveProgress();
  container.innerHTML = `
  <div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-violet-50 flex items-center justify-center p-4">
    <div class="w-full max-w-2xl bg-white rounded-[24px] shadow-xl border border-slate-100 overflow-hidden">
      <div class="px-8 pt-8 pb-4">
        <div class="flex items-center gap-3 mb-6">
          <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold">T</div>
          <div><div class="font-bold text-slate-900">${appConfig.appName}</div><div class="text-xs text-slate-500">${appConfig.tagline}</div></div>
          <div class="ml-auto text-xs px-2 py-1 bg-indigo-50 text-indigo-600 rounded-full">Setup ${state.step}/${state.totalSteps}</div>
        </div>
        <div class="w-full h-2 bg-slate-100 rounded-full mb-6 overflow-hidden"><div class="h-full bg-indigo-600 transition-all" style="width:${state.progress}%"></div></div>
        <div id="setup-content"></div>
      </div>
    </div>
  </div>`;

  const content = container.querySelector('#setup-content');
  if (state.step === 1) renderStep1(content, container, onComplete);
  else if (state.step === 2) renderStep2(content, container, onComplete);
  else if (state.step === 3) renderStep3(content, container, onComplete);
  else if (state.step === 4) renderStep4(content, container, onComplete);
  else if (state.step === 5) renderStep5(content, container, onComplete);
}

function renderStep1(content, container, onComplete) {
  content.innerHTML = `
    <h1 class="text-2xl font-bold text-slate-900">Selamat datang di Tautan</h1>
    <p class="text-sm text-slate-600 mt-2">Satu aplikasi untuk membuat link pendek, microsite, formulir, QR code, dan halaman berbagi aman. Capai nilai pertama dalam 1 menit.</p>
    <div class="mt-6 grid gap-3">
      <div class="p-4 rounded-xl border-2 ${state.mode==='demo'?'border-indigo-600 bg-indigo-50':'border-slate-200'} cursor-pointer" onclick="window.setMode('demo')">
        <div class="flex items-start gap-3"><div class="text-xl">⚡</div><div><div class="font-semibold">Demo Mode <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full ml-2">Direkomendasikan</span></div><div class="text-xs text-slate-600 mt-1">Tanpa password, pilih role/sample user, langsung coba. Cocok untuk eksplorasi cepat.</div></div><div class="ml-auto">${state.mode==='demo'?'✅':''}</div></div>
      </div>
      <div class="p-4 rounded-xl border-2 ${state.mode==='production'?'border-indigo-600 bg-indigo-50':'border-slate-200'} cursor-pointer" onclick="window.setMode('production')">
        <div class="flex items-start gap-3"><div class="text-xl">🔒</div><div><div class="font-semibold">Production Mode</div><div class="text-xs text-slate-600 mt-1">Butuh password, data nyata, untuk penggunaan sebenarnya.</div></div><div class="ml-auto">${state.mode==='production'?'✅':''}</div></div>
      </div>
    </div>
    <div class="mt-6 flex justify-end"><button onclick="window.nextStep()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-medium">Lanjut →</button></div>
    <div class="mt-4 text-xs text-slate-400">Mode dapat diubah nanti di Settings. Demo Mode mudah dinonaktifkan via feature flag.</div>
  `;
  window.setMode = (m) => { state.mode = m; saveProgress(); render(container, onComplete); };
  window.nextStep = () => { state.step = 2; saveProgress(); render(container, onComplete); };
}

function renderStep2(content, container, onComplete) {
  const usersHtml = demoUsers.map(u => `
    <div class="p-3 rounded-xl border ${state.selectedUser?.id===u.id?'border-indigo-600 bg-indigo-50':'border-slate-200 hover:border-slate-300'} cursor-pointer flex gap-3" onclick="window.pickUser('${u.id}')">
      <div class="text-2xl">${u.avatar}</div>
      <div class="flex-1"><div class="font-medium text-sm text-slate-900">${u.name} <span class="text-xs px-2 py-0.5 bg-slate-100 rounded-full">${u.displayRole}</span></div><div class="text-xs text-slate-500">${u.description}</div></div>
      <div>${state.selectedUser?.id===u.id?'✅':''}</div>
    </div>
  `).join('');
  content.innerHTML = `
    <h2 class="text-xl font-bold">Pilih peran Anda</h2>
    <p class="text-sm text-slate-600 mt-1">Kami sesuaikan template & pengaturan awal berdasarkan peran.</p>
    <div class="mt-4 grid gap-2 max-h-[320px] overflow-auto pr-1">${usersHtml}</div>
    <div class="mt-6 flex justify-between"><button onclick="window.prevStep()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">← Kembali</button><button onclick="window.nextStep2()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-medium">Lanjut →</button></div>
  `;
  window.pickUser = (id) => {
    const u = demoUsers.find(x=>x.id===id);
    state.selectedUser = u;
    state.selectedRole = u.role;
    const tmpl = roleTemplates[u.role] || roleTemplates.personal;
    state.workspaceName = tmpl.workspaceName;
    state.settings.workspace.name = tmpl.workspaceName;
    state.settings.workspace.type = u.workspace_type;
    saveProgress();
    render(container, onComplete);
  };
  window.prevStep = () => { state.step=1; saveProgress(); render(container,onComplete); };
  window.nextStep2 = () => {
    if(!state.selectedUser) { showToast('Pilih peran dulu','error'); return; }
    state.step=3; saveProgress(); render(container,onComplete);
  };
}

function renderStep3(content, container, onComplete) {
  content.innerHTML = `
    <h2 class="text-xl font-bold">Mau pakai data contoh?</h2>
    <p class="text-sm text-slate-600 mt-1">Data contoh membantu Anda paham tanpa layar kosong. Bisa dihapus kapan saja.</p>
    <div class="mt-6 grid gap-3">
      <div class="p-4 rounded-xl border-2 ${state.sampleData?'border-indigo-600 bg-indigo-50':'border-slate-200'} cursor-pointer" onclick="window.setSample(true)">
        <div class="flex gap-3"><div class="text-xl">✨</div><div><div class="font-semibold">Ya, gunakan data contoh <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">Direkomendasikan</span></div><div class="text-xs text-slate-600 mt-1">Campaign seminar, shortlink, microsite, form, QR, analytics sudah terisi. Langsung lihat hasil.</div></div><div class="ml-auto">${state.sampleData?'✅':''}</div></div>
      </div>
      <div class="p-4 rounded-xl border-2 ${!state.sampleData?'border-indigo-600 bg-indigo-50':'border-slate-200'} cursor-pointer" onclick="window.setSample(false)">
        <div class="flex gap-3"><div class="text-xl">📄</div><div><div class="font-semibold">Tidak, mulai kosong</div><div class="text-xs text-slate-600 mt-1">Mulai dari nol, kami buatkan workspace pertama otomatis agar tidak kosong mati.</div></div><div class="ml-auto">${!state.sampleData?'✅':''}</div></div>
      </div>
    </div>
    <div class="mt-6 flex justify-between"><button onclick="window.prevStep()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">← Kembali</button><button onclick="window.nextStep3()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Lanjut →</button></div>
  `;
  window.setSample = (v)=>{ state.sampleData=v; saveProgress(); render(container,onComplete); };
  window.prevStep = ()=>{ state.step=2; saveProgress(); render(container,onComplete); };
  window.nextStep3 = ()=>{ state.step=4; saveProgress(); render(container,onComplete); };
}

function renderStep4(content, container, onComplete) {
  const detectedLang = state.settings.language;
  const detectedTz = state.settings.timezone;
  const detectedTheme = state.settings.theme;
  content.innerHTML = `
    <h2 class="text-xl font-bold">Pengaturan default</h2>
    <p class="text-sm text-slate-600 mt-1">Kami deteksi otomatis, bisa lewati & ubah nanti di Settings.</p>
    <div class="mt-4 space-y-4">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs text-slate-600">Bahasa</label><input value="${detectedLang}" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm bg-slate-50" readonly><div class="text-[11px] text-slate-400 mt-1">Auto-detect dari browser</div></div>
        <div><label class="text-xs text-slate-600">Timezone</label><input value="${detectedTz}" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm bg-slate-50" readonly></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs text-slate-600">Tema</label><select id="theme-select" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm"><option value="light" ${detectedTheme==='light'?'selected':''}>Terang (Direkomendasikan)</option><option value="dark" ${detectedTheme==='dark'?'selected':''}>Gelap</option></select></div>
        <div><label class="text-xs text-slate-600">Nama Workspace</label><input id="ws-name" value="${state.workspaceName}" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm"></div>
      </div>
      <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-800">💡 Pengaturan lanjutan seperti UTM, SEO, expiry, custom domain disembunyikan dulu untuk mengurangi friksi. Bisa diatur nanti.</div>
    </div>
    <div class="mt-6 flex justify-between"><button onclick="window.prevStep()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">← Kembali</button><div class="flex gap-2"><button onclick="window.skip()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">Lewati</button><button onclick="window.nextStep4()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Lanjut →</button></div></div>
  `;
  window.prevStep = ()=>{ state.step=3; saveProgress(); render(container,onComplete); };
  window.skip = ()=>{ state.step=5; saveProgress(); render(container,onComplete); };
  window.nextStep4 = ()=>{
    state.settings.theme = document.getElementById('theme-select').value;
    state.workspaceName = document.getElementById('ws-name').value || state.workspaceName;
    state.settings.workspace.name = state.workspaceName;
    saveProgress();
    state.step=5; render(container,onComplete);
  };
}

function renderStep5(content, container, onComplete) {
  content.innerHTML = `
    <h2 class="text-xl font-bold">Review & siap!</h2>
    <p class="text-sm text-slate-600 mt-1">Periksa ringkas sebelum mulai.</p>
    <div class="mt-4 bg-slate-50 rounded-xl p-4 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Mode</span><span class="font-medium">${state.mode} ${state.mode==='demo'?'⚡':''}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Peran</span><span class="font-medium">${state.selectedUser?.name} (${state.selectedUser?.displayRole})</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Data contoh</span><span class="font-medium">${state.sampleData?'Ya ✨':'Tidak'}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Bahasa</span><span class="font-medium">${state.settings.language}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Timezone</span><span class="font-medium">${state.settings.timezone}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Tema</span><span class="font-medium">${state.settings.theme}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Workspace</span><span class="font-medium">${state.workspaceName}</span></div>
    </div>
    <div class="mt-4 p-3 bg-indigo-50 border border-indigo-200 rounded-xl text-xs text-indigo-800">✅ Setelah ini kami buatkan workspace pertama otomatis, jadi Anda tidak mendarat di layar kosong mati. Checklist onboarding 3-5 item akan muncul.</div>
    <div class="mt-6 flex justify-between"><button onclick="window.prevStep()" class="px-5 py-2.5 bg-white border rounded-xl text-sm">← Kembali</button><button onclick="window.finish()" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-medium">Mulai Tautan 🚀</button></div>
  `;
  window.prevStep = ()=>{ state.step=4; saveProgress(); render(container,onComplete); };
  window.finish = ()=>{
    // Create workspace
    const wsId = generateId();
    const workspaces = storage.getWorkspaces();
    workspaces.push({ id: wsId, owner_id: state.selectedUser.id, name: state.workspaceName, type: state.settings.workspace.type, branding: {}, settings: state.settings, created_at: nowISO() });
    storage.setWorkspaces(workspaces);
    storage.set('current_workspace_id', wsId);
    // Set user
    storage.setCurrentUser({ id: state.selectedUser.id, name: state.selectedUser.name, role: state.selectedUser.role, displayRole: state.selectedUser.displayRole, avatar: state.selectedUser.avatar, workspace_id: wsId, workspace_role: state.selectedUser.workspace_role||'owner', is_superadmin: state.selectedUser.is_superadmin||false, mode: state.mode });
    // Settings
    storage.setSettings(state.settings);
    // Sample data?
    if (state.sampleData) {
      generateSampleData(state.selectedUser, wsId);
    } else {
      clearSampleData();
    }
    // Mark setup complete
    storage.setSetupProgress({ ...state, completed: true, completed_at: nowISO() });
    storage.setOnboarding({ completed: ['workspace_created'], dismissed: [], checklist: [{ id: 'create_shortlink', done: false },{ id: 'create_microsite', done: false },{ id: 'create_form', done: false },{ id: 'view_analytics', done: false }] });
    showToast('Workspace dibuat! Selamat datang di Tautan');
    onComplete && onComplete();
  };
}
