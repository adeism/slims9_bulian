export function renderEmptyState({ icon, title, desc, ctaText, ctaAction, secondaryText, secondaryAction }) {
  return `
  <div class="flex flex-col items-center justify-center py-16 px-6 text-center bg-white rounded-2xl border border-dashed border-slate-200">
    <div class="w-16 h-16 rounded-2xl bg-indigo-50 flex items-center justify-center mb-4 text-2xl">${icon||'📦'}</div>
    <h3 class="text-lg font-semibold text-slate-900">${title}</h3>
    <p class="text-sm text-slate-500 mt-1 max-w-md">${desc}</p>
    <div class="mt-6 flex flex-col sm:flex-row gap-3">
      ${ctaText ? `<button onclick="${ctaAction}" class="px-5 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-medium hover:bg-indigo-700">${ctaText}</button>` : ''}
      ${secondaryText ? `<button onclick="${secondaryAction}" class="px-5 py-2.5 bg-white border text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50">${secondaryText}</button>` : ''}
    </div>
  </div>`;
}
