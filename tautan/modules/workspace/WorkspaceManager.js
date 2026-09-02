import { storage, generateId, nowISO, addAuditLog } from '../../core/storage.js';
import { showToast } from '../../shared/components/Toast.js';

export function renderWorkspace(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  const workspaces = storage.getWorkspaces();
  const current = workspaces.find(w=>w.id===wsId);
  const members = storage.get('workspace_members', []).filter(m=>m.workspace_id===wsId);
  const user = storage.getCurrentUser();

  container.innerHTML = `
    <div class="space-y-4">
      <div class="bg-white rounded-2xl border p-5">
        <h2 class="font-bold text-lg">Workspace: ${current?.name||'Unknown'}</h2>
        <div class="text-sm text-slate-500 mt-1">Tipe: ${current?.type||'personal'} • Owner: ${user?.name} • ID: ${wsId?.slice(0,8)}</div>
        <div class="mt-4 grid md:grid-cols-3 gap-3">
          <div class="p-3 bg-slate-50 rounded-xl"><div class="text-xs text-slate-500">Branding</div><div class="text-sm font-medium mt-1">Logo, warna, domain</div><button class="mt-2 px-3 py-1 bg-white border rounded-xl text-xs">Atur branding</button></div>
          <div class="p-3 bg-slate-50 rounded-xl"><div class="text-xs text-slate-500">Billing</div><div class="text-sm font-medium mt-1">Free plan • 30 hari analitik</div><button class="mt-2 px-3 py-1 bg-white border rounded-xl text-xs">Upgrade</button></div>
          <div class="p-3 bg-slate-50 rounded-xl"><div class="text-xs text-slate-500">Audit Log</div><div class="text-sm font-medium mt-1">${storage.getAuditLogs().length} aktivitas</div><button onclick="window.viewAudit()" class="mt-2 px-3 py-1 bg-white border rounded-xl text-xs">Lihat log</button></div>
        </div>
      </div>

      <div class="bg-white rounded-2xl border p-5">
        <div class="flex justify-between items-center"><h3 class="font-semibold">Anggota (${members.length+1})</h3><button onclick="window.inviteMember()" class="px-3 py-1.5 bg-indigo-600 text-white rounded-xl text-xs">+ Undang</button></div>
        <div class="mt-3 space-y-2">
          <div class="flex items-center gap-3 p-2.5 bg-indigo-50 border border-indigo-200 rounded-xl"><div class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center text-sm">${user?.avatar||'👤'}</div><div><div class="text-sm font-medium">${user?.name} (Anda) • ${user?.workspace_role||'owner'}</div><div class="text-xs text-slate-500">${user?.displayRole}</div></div><span class="ml-auto text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded-full">Owner</span></div>
          ${members.map(m=>`<div class="flex items-center gap-3 p-2.5 border rounded-xl"><div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center">👤</div><div><div class="text-sm font-medium">${m.email} • ${m.role}</div><div class="text-xs text-slate-500">Invited ${m.created_at?.slice(0,10)}</div></div></div>`).join('')}
        </div>
        <div class="mt-3 text-[11px] text-slate-400">Role: Owner (billing, semua), Admin (pengaturan & resource), Editor (create/edit/publish), Contributor (draft), Viewer (lihat dashboard).</div>
      </div>

      <div class="bg-white rounded-2xl border p-5">
        <h3 class="font-semibold">Template Organisasi</h3>
        <p class="text-xs text-slate-500 mt-1">Template privat untuk organisasi (Pro). Kategori: Event, Education, Library, UMKM, Creator, Portfolio, Survey, Registration, Contact, Complaint.</p>
        <button class="mt-3 px-3 py-1.5 bg-white border rounded-xl text-xs">Buat template privat</button>
      </div>
    </div>
  `;

  window.inviteMember = () => {
    const email = prompt('Email anggota:');
    if (!email) return;
    const role = prompt('Role (owner/admin/editor/contributor/viewer):','editor')||'editor';
    const list = storage.get('workspace_members', []);
    list.push({ id: generateId(), workspace_id: wsId, email, role, created_at: nowISO() });
    storage.set('workspace_members', list);
    addAuditLog('workspace.invite', `workspace:${wsId}`, { email, role });
    showToast('Undangan dikirim ke '+email);
    renderWorkspace(containerId);
  };
  window.viewAudit = () => {
    const logs = storage.getAuditLogs().slice(0,20);
    const html = logs.map(l=>`<div class="text-xs p-2 border-b"><span class="font-mono">${l.created_at.slice(0,19)}</span> <span class="font-medium">${l.actor_name}</span> ${l.action} ${l.target}</div>`).join('') || 'No logs';
    import('../../shared/components/Modal.js').then(m=> m.openModal({ title: 'Audit Log', content: `<div class="max-h-96 overflow-auto">${html}</div>` }));
  };
}
