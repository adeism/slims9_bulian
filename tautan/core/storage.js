// Storage abstraction - Demo Mode localStorage, future API ready
const PREFIX = 'tautan_';

function getKey(k) { return PREFIX + k; }

export const storage = {
  get(key, def = null) {
    try {
      const raw = localStorage.getItem(getKey(key));
      return raw ? JSON.parse(raw) : def;
    } catch { return def; }
  },
  set(key, val) {
    try {
      localStorage.setItem(getKey(key), JSON.stringify(val));
      return true;
    } catch { return false; }
  },
  remove(key) {
    try { localStorage.removeItem(getKey(key)); } catch {}
  },
  // typed helpers
  getWorkspaces() { return this.get('workspaces', []); },
  setWorkspaces(v) { return this.set('workspaces', v); },
  getLinks() { return this.get('links', []); },
  setLinks(v) { return this.set('links', v); },
  getMicrosites() { return this.get('microsites', []); },
  setMicrosites(v) { return this.set('microsites', v); },
  getForms() { return this.get('forms', []); },
  setForms(v) { return this.set('forms', v); },
  getFormResponses() { return this.get('form_responses', []); },
  setFormResponses(v) { return this.set('form_responses', v); },
  getQrCodes() { return this.get('qr_codes', []); },
  setQrCodes(v) { return this.set('qr_codes', v); },
  getCampaigns() { return this.get('campaigns', []); },
  setCampaigns(v) { return this.set('campaigns', v); },
  getMedia() { return this.get('media_assets', []); },
  setMedia(v) { return this.set('media_assets', v); },
  getAnalyticsEvents() { return this.get('analytics_events', []); },
  setAnalyticsEvents(v) { return this.set('analytics_events', v); },
  getAuditLogs() { return this.get('audit_logs', []); },
  setAuditLogs(v) { return this.set('audit_logs', v); },
  getCurrentUser() { return this.get('current_user', null); },
  setCurrentUser(v) { return this.set('current_user', v); },
  getSetupProgress() { return this.get('setup_progress', null); },
  setSetupProgress(v) { return this.set('setup_progress', v); },
  getSettings() { return this.get('settings', null); },
  setSettings(v) { return this.set('settings', v); },
  getOnboarding() { return this.get('onboarding', { completed: [], dismissed: [] }); },
  setOnboarding(v) { return this.set('onboarding', v); },
};

export function generateId() {
  return Date.now().toString(36) + Math.random().toString(36).slice(2,8);
}

export function uid() { return generateId(); }

export function nowISO() { return new Date().toISOString(); }

// Audit log helper
export function addAuditLog(action, target, metadata = {}) {
  const logs = storage.getAuditLogs();
  const user = storage.getCurrentUser();
  logs.unshift({
    id: generateId(),
    actor_id: user?.id || 'system',
    actor_name: user?.name || 'System',
    action,
    target,
    metadata,
    created_at: nowISO()
  });
  // keep 500 max
  if (logs.length > 500) logs.length = 500;
  storage.setAuditLogs(logs);
}
