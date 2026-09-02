# Default Settings — Tautan

## Philosophy
- Sensible defaults reduce friction, reversible, visible, marked recommended.
- Avoid too many decisions in first session.
- Use auto-detect when safe.

## Default Settings Object (core/config.js)

```js
defaultSettings: {
  language: autoDetect (navigator.language || 'id-ID'),
  timezone: autoDetect (Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Jakarta'),
  theme: autoDetect (prefers-color-scheme dark? 'dark' : 'light', default 'light'),
  dateFormat: autoDetect (locale date, default 'DD/MM/YYYY'),
  timeFormat: '24h',
  currency: 'IDR',
  firstDayOfWeek: 1, // Monday

  workspace: {
    type: 'personal', // personal | organization
    name: auto from user role, e.g., "Workspace Budi"
  },

  shortlink: {
    domain: 'tautan.id', // MVP default
    slugLength: 5, // min for user
    redirectType: 302, // 302 temporary default, 301 permanent optional
    utmEnabled: false, // advanced hidden initially
    expiryEnabled: false,
    passwordEnabled: false,
    pauseEnabled: false
  },

  microsite: {
    theme: 'clean-light', // recommended
    seoEnabled: false, // progressive disclosure
    favicon: default,
    visibility: 'public', // public | password
    idleTimeoutEnabled: true,
    idleTimeoutSeconds: 60, // default 60 detik
    accessExpiresAt: null,
    status: 'draft' // draft | published
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
    successType: 'message', // message | redirect
    successMessage: 'Terima kasih! Respons Anda telah tercatat.',
    status: 'draft'
  },

  qr: {
    type: 'static', // static MVP, dynamic v1.1
    format: 'png', // png | svg
    size: 512,
    color: '#111827',
    bgColor: '#ffffff',
    logoEnabled: false,
    logo: null,
    tracking: true
  },

  analytics: {
    retentionDays: 30, // free 30, pro 365, team custom
    ipHash: true,
    botFiltering: true,
    utmTracking: true,
    countryTracking: false // privacy opt-in
  },

  security: {
    maxFailedAttempts: 5,
    lockoutSeconds: 60,
    passwordHashAlgo: 'argon2id', // demo simulates
    sessionRegeneration: true,
    revokeVersion: 1
  },

  media: {
    maxSizeMB: 5,
    allowedMime: ['image/jpeg','image/png','image/webp','image/svg+xml','application/pdf'],
    optimization: true,
    virusScan: false // future
  },

  onboarding: {
    showChecklist: true,
    checklistItems: 4,
    quickTutorialEnabled: true,
    detailedTutorialEnabled: false, // progressive
    helpCenterEnabled: true,
    contextualHelp: true
  }
}
```

## Smart Defaults per Role

- Personal/Creator: microsite template = Profile + Links, form template = Contact
- UMKM: microsite = Catalog, form = Order, campaign = Promo UMKM
- Perpustakaan: microsite = Layanan Perpustakaan, form = Usulan Koleksi, template = Education/Library
- Sekolah: microsite = Informasi Sekolah, form = Pendaftaran Siswa
- Panitia: campaign = Seminar/Event, microsite = Event, form = Pendaftaran Event
- Organisasi: workspace type = organization, role = Owner

## Recommended Marking
- In UI, recommended choices have badge "Direkomendasikan" and are pre-selected.
- Example: Theme clean-light marked recommended, slugLength 5 recommended, idle timeout 60s recommended.

## Auto-Detect Logic
```js
language: navigator.language || 'id-ID'
timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
theme: window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
dateFormat: (new Intl.DateTimeFormat().resolvedOptions().locale || 'id-ID').includes('en-US') ? 'MM/DD/YYYY' : 'DD/MM/YYYY'
```

## Skip & Change Later
- Non-critical settings (UTM, SEO, expiry, custom domain, branding) hidden behind "Pengaturan lanjutan" toggle.
- User can skip, change later in Settings → Default Settings.

## Review Page
- Before setup ends, show review: mode, role, sample data choice, language, timezone, theme, workspace name.
- Allow edit inline.

## Autosave
- Setup progress autosaved to localStorage key `tautan_setup_progress` every change, debounce 500ms.
- On reload, offer "Lanjutkan setup?".

## First Workspace Auto-Creation
- After setup, auto-create workspace: id = uuid, owner = current user, name = "Workspace {User}" or role-based.
- Auto-create first project if campaign template chosen, else empty workspace with empty state guidance.

## Safe Defaults
- Visibility public only if user explicitly chooses, else draft.
- Password protected default idle timeout enabled true, 60s.
- Analytics IP hash true, bot filtering true.
- No custom script allowed in microsite MVP.
