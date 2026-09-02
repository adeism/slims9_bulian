export function renderHelpCenter(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = `
    <div class="bg-white rounded-2xl border p-5">
      <h3 class="font-semibold">Pusat Bantuan</h3>
      <div class="mt-3 space-y-2 text-sm">
        <details class="border rounded-xl p-3"><summary class="font-medium cursor-pointer">Bagaimana buat shortlink?</summary><p class="text-slate-600 mt-2 text-xs">Klik + Buat Baru → Shortlink → Masukkan URL → Buat. Slug minimal 5 karakter untuk user biasa.</p></details>
        <details class="border rounded-xl p-3"><summary class="font-medium cursor-pointer">Kenapa slug pendek ditolak?</summary><p class="text-slate-600 mt-2 text-xs">User biasa minimal 5 karakter. Super admin boleh 1-4 dengan alasan & audit log.</p></details>
        <details class="border rounded-xl p-3"><summary class="font-medium cursor-pointer">Bagaimana password page & idle timeout?</summary><p class="text-slate-600 mt-2 text-xs">Atur di Microsite/Form Settings → Password protected. Default idle 60 detik, sesi berakhir otomatis, server-side validation.</p></details>
        <details class="border rounded-xl p-3"><summary class="font-medium cursor-pointer">Bagaimana reset onboarding?</summary><p class="text-slate-600 mt-2 text-xs">Settings → Reset onboarding.</p></details>
      </div>
      <div class="mt-4 flex gap-2"><button onclick="window.resetOnboarding()" class="px-3 py-2 bg-white border rounded-xl text-xs">Reset onboarding</button><button onclick="window.resetHelp()" class="px-3 py-2 bg-white border rounded-xl text-xs">Reset hints</button></div>
    </div>
  `;
}
