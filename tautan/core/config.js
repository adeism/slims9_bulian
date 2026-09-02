// Tautan Core Config - feature flags, default settings, smart defaults
export const appConfig = {
  appName: 'Tautan',
  tagline: 'Satu tempat untuk semua link, halaman, form, dan campaign.',
  version: '0.1.0',
  mode: 'demo', // demo | production - controlled by flag
  defaultDomain: 'tautan.id',
};

export const featureFlags = {
  demoMode: {
    enabled: true,
    owner: 'core team',
    purpose: 'No password, role/sample picker for fast first value',
    created: '2026-09-02',
    expires: '2026-12-31',
    cleanup: 'Set false, remove demo/ imports, remove demo badge',
    type: 'temporary'
  },
  onboardingExperiment: {
    enabled: true,
    variant: 'direct-to-task',
    owner: 'ux team',
    purpose: 'Direct-to-task vs generic tour',
    created: '2026-09-02',
    expires: '2026-10-15',
    cleanup: 'Pick winner, remove other path',
    type: 'temporary'
  },
  riskyFeatures: {
    customDomain: { enabled: false, killSwitch: true, owner: 'infra', purpose: 'Custom domain SSL risky', created: '2026-09-02', expires: '2026-11-01', cleanup: 'Enable after stable' },
    fileUpload: { enabled: true, killSwitch: true, owner: 'security', purpose: 'File upload needs scan', created: '2026-09-02', expires: null, cleanup: 'Keep kill switch' },
    aiGenerator: { enabled: false, killSwitch: true, owner: 'ai team', purpose: 'AI campaign generator', created: '2026-09-02', expires: '2027-01-01', cleanup: 'Enable after Ollama tested' }
  },
  killSwitches: {
    shortlinkCreation: true,
    micrositePublish: true,
    formPublish: true,
    qrGeneration: true,
    analyticsCollection: true
  },
  rollout: {
    campaignWizard: { enabled: true, percentage: 100, owner: 'product', purpose: 'Campaign wizard', created: '2026-09-02', expires: '2026-09-30', cleanup: 'Remove after 100%' },
    analyticsFunnel: { enabled: true, percentage: 100 }
  }
};

function autoDetect() {
  try {
    const lang = navigator.language || 'id-ID';
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Jakarta';
    const isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = isDark ? 'dark' : 'light';
    const locale = lang;
    const dateFormat = locale.includes('en-US') ? 'MM/DD/YYYY' : 'DD/MM/YYYY';
    return { lang, tz, theme, dateFormat };
  } catch {
    return { lang: 'id-ID', tz: 'Asia/Jakarta', theme: 'light', dateFormat: 'DD/MM/YYYY' };
  }
}

const detected = (typeof navigator !== 'undefined') ? autoDetect() : { lang: 'id-ID', tz: 'Asia/Jakarta', theme: 'light', dateFormat: 'DD/MM/YYYY' };

export const defaultSettings = {
  language: detected.lang,
  timezone: detected.tz,
  theme: detected.theme,
  dateFormat: detected.dateFormat,
  timeFormat: '24h',
  currency: 'IDR',
  firstDayOfWeek: 1,

  workspace: {
    type: 'personal',
    name: 'Workspace Saya'
  },

  shortlink: {
    domain: 'tautan.id',
    slugLength: 5,
    redirectType: 302,
    utmEnabled: false,
    expiryEnabled: false,
    passwordEnabled: false,
    pauseEnabled: false
  },

  microsite: {
    theme: 'clean-light',
    seoEnabled: false,
    favicon: null,
    visibility: 'public',
    idleTimeoutEnabled: true,
    idleTimeoutSeconds: 60,
    accessExpiresAt: null,
    status: 'draft'
  },

  form: {
    visibility: 'public',
    idleTimeoutEnabled: true,
    idleTimeoutSeconds: 60,
    limitResponseEnabled: false,
    limitResponseCount: 100,
    oneResponsePerEmail: false,
    loginOnly: false,
    notificationEmail: false,
    successType: 'message',
    successMessage: 'Terima kasih! Respons Anda telah tercatat.',
    status: 'draft'
  },

  qr: {
    type: 'static',
    format: 'png',
    size: 512,
    color: '#111827',
    bgColor: '#ffffff',
    logoEnabled: false,
    logo: null,
    tracking: true
  },

  analytics: {
    retentionDays: 30,
    ipHash: true,
    botFiltering: true,
    utmTracking: true,
    countryTracking: false
  },

  security: {
    maxFailedAttempts: 5,
    lockoutSeconds: 60,
    passwordHashAlgo: 'argon2id',
    sessionRegeneration: true,
    revokeVersion: 1
  },

  media: {
    maxSizeMB: 5,
    allowedMime: ['image/jpeg','image/png','image/webp','image/svg+xml','application/pdf'],
    optimization: true,
    virusScan: false
  },

  onboarding: {
    showChecklist: true,
    checklistItems: 4,
    quickTutorialEnabled: true,
    detailedTutorialEnabled: false,
    helpCenterEnabled: true,
    contextualHelp: true
  }
};

export const roleTemplates = {
  personal: { workspaceName: 'Workspace Pribadi', microsite: 'profile-links', form: 'contact', campaign: null },
  creator: { workspaceName: 'Workspace Kreator', microsite: 'portfolio', form: 'contact', campaign: 'creator-launch' },
  umkm: { workspaceName: 'Toko Saya', microsite: 'catalog', form: 'order', campaign: 'promo-umkm' },
  sekolah: { workspaceName: 'Sekolah', microsite: 'school-info', form: 'registration', campaign: 'ppdb' },
  perpustakaan: { workspaceName: 'Perpustakaan', microsite: 'library-service', form: 'book-suggestion', campaign: 'literasi' },
  panitia: { workspaceName: 'Panitia Event', microsite: 'event', form: 'event-registration', campaign: 'seminar-literasi' },
  organisasi: { workspaceName: 'Organisasi', microsite: 'org-profile', form: 'member-registration', campaign: 'rekrutmen' },
  superadmin: { workspaceName: 'Admin Tautan', microsite: 'admin', form: 'report', campaign: 'system' }
};

export const reservedSlugs = ['admin','api','login','logout','register','dashboard','settings','help','p','m','f','qr','embed','assets','static','public','private','www','mail','ftp','tautan','s','link','form','campaign','workspace','billing','templates'];

export const themes = [
  { id: 'clean-light', name: 'Bersih Terang', recommended: true, colors: { bg: '#ffffff', text: '#111827', primary: '#4f46e5' } },
  { id: 'dark-mode', name: 'Gelap', recommended: false, colors: { bg: '#111827', text: '#f9fafb', primary: '#818cf8' } },
  { id: 'library-warm', name: 'Perpustakaan Hangat', recommended: false, colors: { bg: '#fffbeb', text: '#78350f', primary: '#d97706' } },
  { id: 'umkm-fresh', name: 'UMKM Segar', recommended: false, colors: { bg: '#ecfdf5', text: '#064e3b', primary: '#10b981' } },
  { id: 'event-bold', name: 'Event Berani', recommended: false, colors: { bg: '#eff6ff', text: '#1e3a8a', primary: '#2563eb' } },
];
