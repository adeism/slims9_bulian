export function showToast(msg, type='success') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'fixed bottom-4 right-4 z-[9999] flex flex-col gap-2';
    document.body.appendChild(container);
  }
  const el = document.createElement('div');
  el.className = `px-4 py-3 rounded-xl shadow-lg text-sm font-medium max-w-sm transition-all ${type==='success'?'bg-slate-900 text-white': type==='error'?'bg-red-600 text-white':'bg-white border'}`;
  el.textContent = msg;
  container.appendChild(el);
  setTimeout(()=> { el.style.opacity='0'; el.style.transform='translateY(10px)'; setTimeout(()=> el.remove(),300); }, 3000);
}
