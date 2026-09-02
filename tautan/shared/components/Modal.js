export function openModal({ title, content, onClose }) {
  let overlay = document.getElementById('modal-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'modal-overlay';
    overlay.className = 'fixed inset-0 z-[100] bg-black/40 backdrop-blur-sm flex items-center justify-center p-4';
    document.body.appendChild(overlay);
  }
  overlay.innerHTML = `<div class="bg-white rounded-2xl w-full max-w-lg shadow-xl max-h-[90vh] overflow-auto"><div class="p-5 border-b flex justify-between items-center"><h3 class="font-semibold">${title}</h3><button id="modal-close" class="w-8 h-8 rounded-full hover:bg-slate-100">✕</button></div><div class="p-5">${content}</div></div>`;
  overlay.style.display='flex';
  overlay.querySelector('#modal-close').onclick = () => { overlay.style.display='none'; onClose&&onClose(); };
  overlay.onclick = (e)=> { if(e.target===overlay){ overlay.style.display='none'; onClose&&onClose(); } };
}
export function closeModal() {
  const o = document.getElementById('modal-overlay');
  if(o) o.style.display='none';
}
