import { storage } from '../core/storage.js';
import { showToast } from '../shared/components/Toast.js';

export function renderChecklist(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const onboarding = storage.getOnboarding();
  const items = [
    { id: 'create_shortlink', title: 'Buat shortlink pertama', desc: '3 langkah, dapat link + QR', action: '#/shortlinks', done: onboarding.completed?.includes('create_shortlink') },
    { id: 'create_microsite', title: 'Buat microsite dari template', desc: 'Publish dalam 2 menit', action: '#/microsites', done: onboarding.completed?.includes('create_microsite') },
    { id: 'create_form', title: 'Buat form pendaftaran', desc: 'Drag-drop, dapat respons', action: '#/forms', done: onboarding.completed?.includes('create_form') },
    { id: 'view_analytics', title: 'Lihat analitik', desc: 'Funnel QR → klik → view → submission', action: '#/analytics', done: onboarding.completed?.includes('view_analytics') }
  ];
  // first item pre-completed if sample data
  const hasSample = storage.getLinks().length > 0;
  if (hasSample && !items[0].done) {
    // mark first as done visually but not fully
  }
  const doneCount = items.filter(i=>i.done).length;
  container.innerHTML = `
    <div class="bg-white rounded-2xl border p-5">
      <div class="flex justify-between items-center mb-3"><h3 class="font-semibold text-sm">Checklist aktivasi (${doneCount}/${items.length})</h3><button onclick="window.dismissChecklist()" class="text-xs text-slate-500 hover:text-slate-700">Sembunyikan</button></div>
      <div class="space-y-2">
        ${items.map(it=>`
          <div class="flex items-center gap-3 p-2.5 rounded-xl ${it.done?'bg-green-50 border border-green-200':'bg-slate-50 border border-slate-200 hover:border-slate-300'} cursor-pointer" onclick="window.goChecklist('${it.action}','${it.id}')">
            <div class="w-6 h-6 rounded-full ${it.done?'bg-green-600 text-white':'bg-white border'} flex items-center justify-center text-xs">${it.done?'✓':'○'}</div>
            <div class="flex-1"><div class="text-sm font-medium ${it.done?'line-through text-slate-500':''}">${it.title}</div><div class="text-xs text-slate-500">${it.desc}</div></div>
            <div class="text-xs text-indigo-600">→</div>
          </div>
        `).join('')}
      </div>
      <div class="mt-3 text-[11px] text-slate-400">Fokus pada aktivasi, bukan semua fitur. Checklist bisa dibuka lagi di Settings → Reset onboarding.</div>
    </div>
  `;
  window.goChecklist = (action, id) => {
    if (!onboarding.completed.includes(id)) {
      // navigate
      window.location.hash = action;
    } else {
      window.location.hash = action;
    }
  };
  window.dismissChecklist = () => {
    container.style.display='none';
    const ob = storage.getOnboarding();
    ob.dismissed.push('checklist');
    storage.setOnboarding(ob);
  };
}

export function completeChecklistItem(id) {
  const ob = storage.getOnboarding();
  if (!ob.completed.includes(id)) {
    ob.completed.push(id);
    storage.setOnboarding(ob);
    showToast('Checklist selesai: '+id);
  }
}
