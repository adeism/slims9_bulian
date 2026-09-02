import { storage } from '../core/storage.js';
export function startGuide(step = 'shortlink') {
  const guides = {
    shortlink: { title: 'Buat shortlink 3 langkah', content: '1. Klik + Buat Baru → Shortlink\n2. Masukkan URL tujuan\n3. Klik Buat, dapat link + QR langsung' },
    microsite: { title: 'Microsite drag-drop', content: 'Drag blok dari kiri ke kanvas. Klik blok untuk edit. Publish untuk live.' },
    form: { title: 'Form 3 panel', content: 'Kiri: Field Library, Tengah: Canvas, Kanan: Properties. Drag field ke canvas, atur validasi.' }
  };
  const g = guides[step];
  if(!g) return;
  // simple overlay guide
  const overlay = document.createElement('div');
  overlay.className = 'fixed inset-0 z-[90] bg-black/30 flex items-center justify-center p-4';
  overlay.innerHTML = `<div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-xl"><h3 class="font-bold">${g.title}</h3><p class="text-sm text-slate-600 mt-2 whitespace-pre-line">${g.content}</p><div class="mt-4 flex justify-end gap-2"><button onclick="this.closest('.fixed').remove()" class="px-4 py-2 bg-white border rounded-xl text-sm">Lewati</button><button onclick="this.closest('.fixed').remove(); localStorage.setItem('tautan_guide_${step}','done')" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">Mengerti</button></div></div>`;
  document.body.appendChild(overlay);
}
