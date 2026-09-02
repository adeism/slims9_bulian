import { storage } from '../../core/storage.js';
import { getMetricsSummary, getFunnel } from '../../core/analytics.js';
import { showToast } from '../../shared/components/Toast.js';

export function renderAnalytics(containerId, projectId = null) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  const summary = getMetricsSummary({ workspace_id: wsId, project_id: projectId });
  const funnel = summary.funnel;

  if (summary.total_events===0) {
    import('../../shared/components/EmptyState.js').then(m=>{
      container.innerHTML = m.renderEmptyState({ icon: '📊', title: 'Belum ada data analitik', desc: 'Bagikan link/QR untuk mulai kumpulkan data. Funnel: QR scan → Shortlink click → Microsite view → Form submission', ctaText: 'Buat Shortlink', ctaAction: "window.location.hash='#/shortlinks'", secondaryText: 'Lihat QR Studio', secondaryAction: "window.location.hash='#/qr'" });
    });
    return;
  }

  container.innerHTML = `
    <div class="space-y-4">
      <div class="flex justify-between items-center"><h2 class="text-lg font-bold">Analytics ${projectId?'Campaign':''}</h2><button onclick="window.exportAnalytics()" class="px-3 py-1.5 bg-white border rounded-xl text-xs">Export</button></div>
      
      <div class="bg-white rounded-2xl border p-5">
        <h3 class="font-semibold text-sm mb-3">Funnel: QR scan → Click → View → Submission</h3>
        <div class="grid grid-cols-4 gap-3">
          <div class="p-3 bg-slate-50 rounded-xl text-center"><div class="text-xs text-slate-500">Reach</div><div class="text-xl font-bold">${funnel.reach}</div><div class="text-[11px] text-slate-400">QR scan + click</div></div>
          <div class="p-3 bg-blue-50 rounded-xl text-center"><div class="text-xs text-slate-500">Visit</div><div class="text-xl font-bold">${funnel.visit}</div><div class="text-[11px] text-slate-400">Microsite view</div></div>
          <div class="p-3 bg-amber-50 rounded-xl text-center"><div class="text-xs text-slate-500">Engagement</div><div class="text-xl font-bold">${funnel.engagement}</div><div class="text-[11px] text-slate-400">Button/scroll/video</div></div>
          <div class="p-3 bg-green-50 rounded-xl text-center"><div class="text-xs text-slate-500">Conversion</div><div class="text-xl font-bold">${funnel.conversion}</div><div class="text-[11px] text-slate-400">${funnel.completion_rate}% rate</div></div>
        </div>
        <div class="mt-4 h-2 bg-slate-100 rounded-full flex overflow-hidden">
          <div class="bg-slate-400" style="width:${funnel.reach?100:0}%"></div>
          <div class="bg-blue-500" style="width:${funnel.visit && funnel.reach ? (funnel.visit/funnel.reach*100):0}%"></div>
          <div class="bg-amber-500" style="width:${funnel.engagement && funnel.visit ? (funnel.engagement/funnel.visit*100):0}%"></div>
          <div class="bg-green-600" style="width:${funnel.completion_rate}%"></div>
        </div>
      </div>

      <div class="grid md:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border p-4"><div class="text-xs text-slate-500">Total Events</div><div class="text-2xl font-bold mt-1">${summary.total_events}</div><div class="text-xs text-slate-400 mt-1">Unique visitors: ${summary.unique_visitors}</div></div>
        <div class="bg-white rounded-2xl border p-4"><div class="text-xs text-slate-500">Device</div><div class="mt-2 space-y-1 text-xs">${Object.entries(summary.device).map(([k,v])=>`<div class="flex justify-between"><span>${k}</span><span class="font-medium">${v}</span></div>`).join('') || 'No data'}</div></div>
        <div class="bg-white rounded-2xl border p-4"><div class="text-xs text-slate-500">Browser</div><div class="mt-2 space-y-1 text-xs">${Object.entries(summary.browser).map(([k,v])=>`<div class="flex justify-between"><span>${k}</span><span class="font-medium">${v}</span></div>`).join('') || 'No data'}</div></div>
      </div>

      <div class="bg-white rounded-2xl border p-5">
        <h3 class="font-semibold text-sm mb-3">Daily Aggregation (30 hari)</h3>
        <div class="overflow-auto">
          <table class="w-full text-xs">
            <thead><tr class="text-slate-400"><th class="text-left p-2">Tanggal</th><th class="text-left p-2">Views</th><th class="text-left p-2">Clicks</th><th class="text-left p-2">Scans</th><th class="text-left p-2">Form Start</th><th class="text-left p-2">Completions</th></tr></thead>
            <tbody>
              ${summary.daily.slice(-10).map(d=>`<tr class="border-t"><td class="p-2">${d.date}</td><td class="p-2">${d.views}</td><td class="p-2">${d.clicks}</td><td class="p-2">${d.scans}</td><td class="p-2">${d.form_starts}</td><td class="p-2">${d.form_completions}</td></tr>`).join('') || '<tr><td colspan="6" class="p-4 text-center text-slate-400">No data</td></tr>'}
            </tbody>
          </table>
        </div>
        <div class="mt-3 text-[11px] text-slate-400">Privasi: IP di-hash, retensi ${storage.getSettings()?.analytics?.retentionDays||30} hari, bot filtering aktif, jangan simpan data personal tanpa consent.</div>
      </div>
    </div>
  `;
  window.exportAnalytics = () => {
    const data = JSON.stringify(summary, null, 2);
    const blob = new Blob([data], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href=url; a.download='analytics.json'; a.click(); URL.revokeObjectURL(url);
    showToast('Export analytics');
  };
}
