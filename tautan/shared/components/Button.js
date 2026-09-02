export function primaryButton(text, onclick, extra='') {
  return `<button onclick="${onclick}" class="px-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-medium hover:bg-indigo-700 transition ${extra}">${text}</button>`;
}
export function secondaryButton(text, onclick) {
  return `<button onclick="${onclick}" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50">${text}</button>`;
}
