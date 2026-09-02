export function createHelpBubble({ id, text, targetSelector, onDismiss }) {
  if (localStorage.getItem('tautan_help_dismissed_'+id)) return;
  const target = document.querySelector(targetSelector);
  if (!target) return;
  const bubble = document.createElement('div');
  bubble.className = 'absolute z-40 bg-slate-900 text-white text-xs rounded-xl px-3 py-2 max-w-[220px] shadow-lg';
  bubble.innerHTML = `${text}<button class="ml-2 underline">Tutup</button>`;
  bubble.style.top = (target.offsetTop + target.offsetHeight + 8) + 'px';
  bubble.style.left = target.offsetLeft + 'px';
  bubble.querySelector('button').onclick = () => {
    bubble.remove();
    localStorage.setItem('tautan_help_dismissed_'+id,'1');
    onDismiss && onDismiss();
  };
  target.parentElement.style.position = 'relative';
  target.parentElement.appendChild(bubble);
  // auto remove after 8s
  setTimeout(()=> bubble.remove(), 8000);
}
export function resetHelp() {
  Object.keys(localStorage).forEach(k=> { if(k.startsWith('tautan_help_dismissed_')) localStorage.removeItem(k); });
}
