export function detailedTutorial() {
  return {
    title: 'Panduan Lengkap',
    sections: [
      { title: 'Shortlink', content: 'Buat redirect 301/302, UTM, expiry, password, pause, QR, bulk CSV.' },
      { title: 'Microsite', content: 'Editor blok drag-drop, tema, SEO, favicon, publik/password, idle timeout 60s, revoke sessions.' },
      { title: 'Form', content: 'Field library, conditional logic, validasi, password, limit, notifikasi email, export.' },
      { title: 'QR', content: 'Statis MVP, dinamis v1.1, PNG/SVG, warna/logo, tracking.' },
      { title: 'Campaign', content: 'Wizard 5 langkah gabungkan microsite+form+shortlink+QR+analytics.' },
      { title: 'Analytics', content: 'Funnel Reach→Visit→Engagement→Conversion, device/browser/referer/UTM, IP hash.' }
    ]
  };
}
