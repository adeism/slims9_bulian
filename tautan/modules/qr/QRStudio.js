import { storage, generateId, nowISO } from '../../core/storage.js';
import { showToast } from '../../shared/components/Toast.js';

export class QRManager {
  static create({ workspace_id, project_id, resource_type, resource_id, style = {} }) {
    const list = storage.getQrCodes();
    const qr = { id: generateId(), workspace_id, project_id: project_id||null, resource_type, resource_id, style: { color: '#111827', bgColor: '#ffffff', size: 512, logo: null, ...style }, scans: 0, created_at: nowISO() };
    list.unshift(qr);
    storage.setQrCodes(list);
    return { success: true, data: qr };
  }
  static list(wsId) { return storage.getQrCodes().filter(q=>!wsId||q.workspace_id===wsId); }
  static delete(id) { let list = storage.getQrCodes(); list = list.filter(q=>q.id!==id); storage.setQrCodes(list); return { success: true }; }
}

// Simple QR generation using canvas (no external lib, fallback to text)
function drawQR(canvas, text, color='#111827', bg='#ffffff') {
  const ctx = canvas.getContext('2d');
  const size = canvas.width;
  ctx.fillStyle = bg;
  ctx.fillRect(0,0,size,size);
  // Very simple fake QR pattern for demo (real would use qrcode.js)
  ctx.fillStyle = color;
  const modules = 21;
  const cell = size / modules;
  // border
  for(let i=0;i<modules;i++) {
    for(let j=0;j<modules;j++) {
      if (Math.random()>0.5 || i<2 || j<2 || i>modules-3 || j>modules-3) {
        // leave some pattern
        if ((i+j)%3!==0) ctx.fillRect(i*cell, j*cell, cell-1, cell-1);
      }
    }
  }
  // finder patterns
  [[0,0],[0,modules-7],[modules-7,0]].forEach(([x,y])=>{
    ctx.fillStyle = color;
    ctx.fillRect(x*cell, y*cell, 7*cell, 7*cell);
    ctx.fillStyle = bg;
    ctx.fillRect((x+1)*cell, (y+1)*cell, 5*cell, 5*cell);
    ctx.fillStyle = color;
    ctx.fillRect((x+2)*cell, (y+2)*cell, 3*cell, 3*cell);
  });
  // text label
  ctx.fillStyle = color;
  ctx.font = '10px monospace';
  ctx.fillText(text.slice(0,20), 5, size-5);
}

export function renderQRStudio(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const wsId = storage.get('current_workspace_id');
  const qrs = QRManager.list(wsId);
  const pendingSource = JSON.parse(localStorage.getItem('tautan_qr_source')||'null');

  container.innerHTML = `
    <div class="flex justify-between items-center mb-4"><h2 class="text-lg font-bold">QR Studio (${qrs.length})</h2><button onclick="window.openCreateQR()" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">+ Buat QR</button></div>
    ${pendingSource?`<div class="mb-4 p-3 bg-indigo-50 border border-indigo-200 rounded-xl text-sm">Sumber terdeteksi: ${pendingSource.type} ${pendingSource.id} <button onclick="window.createFromPending()" class="ml-2 px-3 py-1 bg-indigo-600 text-white rounded-lg text-xs">Buat QR dari ini</button></div>`:''}
    <div class="grid md:grid-cols-2 gap-4">
      <div class="bg-white rounded-2xl border p-5">
        <h3 class="font-semibold text-sm">Buat QR Baru</h3>
        <div class="mt-3 space-y-3">
          <div><label class="text-xs">Sumber</label><select id="qr-source-type" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm"><option value="link">Shortlink</option><option value="microsite">Microsite</option><option value="form">Form</option><option value="campaign">Campaign</option><option value="external">URL Eksternal</option></select></div>
          <div><label class="text-xs">Pilih resource atau URL</label><select id="qr-resource" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm"></select><input id="qr-external" placeholder="https://..." class="w-full mt-2 px-3 py-2 border rounded-xl text-sm hidden"></div>
          <div class="grid grid-cols-2 gap-2"><div><label class="text-xs">Warna QR</label><input id="qr-color" type="color" value="#111827" class="w-full h-10 rounded-xl"></div><div><label class="text-xs">Warna BG</label><input id="qr-bg" type="color" value="#ffffff" class="w-full h-10 rounded-xl"></div></div>
          <div><label class="text-xs">Format</label><select id="qr-format" class="w-full mt-1 px-3 py-2 border rounded-xl text-sm"><option value="png">PNG (Direkomendasikan)</option><option value="svg">SVG</option></select></div>
          <button onclick="window.generateQRNow()" class="w-full py-2.5 bg-indigo-600 text-white rounded-xl text-sm">Generate QR</button>
          <div class="text-[11px] text-slate-400">QR statis MVP, dinamis v1.1. PNG/SVG, warna & logo, preset desain, UTM tracking, download & duplicate, statistik scan.</div>
        </div>
      </div>
      <div class="bg-white rounded-2xl border p-5">
        <h3 class="font-semibold text-sm">Preview</h3>
        <div class="mt-3 flex flex-col items-center">
          <canvas id="qr-canvas" width="256" height="256" class="border rounded-xl"></canvas>
          <div id="qr-url" class="mt-3 text-xs font-mono text-indigo-600 break-all"></div>
          <div class="mt-3 flex gap-2"><button onclick="window.downloadQR()" class="px-4 py-2 bg-white border rounded-xl text-xs">Download PNG</button><button onclick="window.duplicateQR()" class="px-4 py-2 bg-white border rounded-xl text-xs">Duplikat</button></div>
        </div>
      </div>
    </div>
    <div class="mt-6">
      <h3 class="font-semibold text-sm mb-3">QR Saya</h3>
      <div class="grid md:grid-cols-3 gap-3">
        ${qrs.map(q=>`
          <div class="bg-white rounded-2xl border p-4">
            <div class="flex justify-between"><span class="text-xs px-2 py-0.5 bg-slate-100 rounded-full">${q.resource_type}</span><span class="text-xs">${q.scans||0} scan</span></div>
            <div class="mt-2 text-sm font-medium">${q.resource_type} ${q.resource_id.slice(0,6)}</div>
            <div class="mt-2 w-full h-24 bg-slate-50 rounded-xl flex items-center justify-center text-2xl">🔲</div>
            <div class="mt-3 flex gap-2"><button onclick="window.downloadQR('${q.id}')" class="px-3 py-1 bg-white border rounded-xl text-xs">Download</button><button onclick="window.deleteQR('${q.id}')" class="px-3 py-1 bg-red-50 text-red-600 rounded-xl text-xs">Hapus</button></div>
          </div>
        `).join('') || '<div class="text-sm text-slate-500">Belum ada QR. Buat dari shortlink atau microsite.</div>'}
      </div>
    </div>
  `;

  const sourceTypeEl = document.getElementById('qr-source-type');
  const resourceEl = document.getElementById('qr-resource');
  const externalEl = document.getElementById('qr-external');

  function loadResources() {
    const type = sourceTypeEl.value;
    resourceEl.innerHTML = '';
    if (type==='external') { resourceEl.classList.add('hidden'); externalEl.classList.remove('hidden'); return; }
    resourceEl.classList.remove('hidden'); externalEl.classList.add('hidden');
    let items = [];
    if (type==='link') items = storage.getLinks();
    else if (type==='microsite') items = storage.getMicrosites();
    else if (type==='form') items = storage.getForms();
    else if (type==='campaign') items = storage.getCampaigns();
    resourceEl.innerHTML = items.map(i=>`<option value="${i.id}">${i.title||i.slug} (${i.slug})</option>`).join('') || '<option>Tidak ada</option>';
  }

  sourceTypeEl.addEventListener('change', loadResources);
  loadResources();

  let currentText = 'https://tautan.id/sample';
  let currentQRId = null;

  window.generateQRNow = () => {
    const type = sourceTypeEl.value;
    let text = '';
    if (type==='external') text = externalEl.value || 'https://example.com';
    else {
      const id = resourceEl.value;
      const items = type==='link'?storage.getLinks(): type==='microsite'?storage.getMicrosites(): type==='form'?storage.getForms(): storage.getCampaigns();
      const item = items.find(x=>x.id===id);
      if (item) text = `https://tautan.id/${type==='link'?'': type==='microsite'?'m/': type==='form'?'f/':''}${item.slug}`;
    }
    currentText = text;
    document.getElementById('qr-url').textContent = text;
    const canvas = document.getElementById('qr-canvas');
    const color = document.getElementById('qr-color').value;
    const bg = document.getElementById('qr-bg').value;
    drawQR(canvas, text, color, bg);
    // save
    const wsId = storage.get('current_workspace_id');
    const res = QRManager.create({ workspace_id: wsId, resource_type: type, resource_id: resourceEl.value||'external', style: { color, bgColor: bg } });
    currentQRId = res.data.id;
    showToast('QR dibuat');
    renderQRStudio(containerId);
  };

  window.downloadQR = (id) => {
    const canvas = document.getElementById('qr-canvas');
    const link = document.createElement('a');
    link.download = 'qr-'+(id||currentQRId||'sample')+'.png';
    link.href = canvas.toDataURL();
    link.click();
    showToast('Download QR');
  };

  window.deleteQR = (id) => { if(confirm('Hapus QR?')){ QRManager.delete(id); showToast('Dihapus'); renderQRStudio(containerId); } };
  window.createFromPending = () => {
    const pending = JSON.parse(localStorage.getItem('tautan_qr_source')||'null');
    if (!pending) return;
    sourceTypeEl.value = pending.type;
    loadResources();
    // try select
    setTimeout(()=>{
      resourceEl.value = pending.id;
      window.generateQRNow();
      localStorage.removeItem('tautan_qr_source');
    },100);
  };
  window.duplicateQR = () => { showToast('Duplikat QR (simulasi)'); };

  // initial draw
  const canvas = document.getElementById('qr-canvas');
  if (canvas) drawQR(canvas, currentText);
}
