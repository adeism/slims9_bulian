// Security helpers - slug policy, safe browsing, password, session, rate limit
import { reservedSlugs } from './config.js';
import { storage, addAuditLog } from './storage.js';

const PRIVATE_IP_REGEX = /^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|0\.0\.0\.0|169\.254\.|::1|fc00:|fe80:)/;
const LOCALHOST_REGEX = /(localhost|127\.0\.0\.1|0\.0\.0\.0|::1|169\.254\.169\.254|metadata\.google\.internal)/i;

export function isSafeUrl(urlStr) {
  try {
    const u = new URL(urlStr);
    if (!['http:','https:'].includes(u.protocol)) return { safe: false, reason: 'Protokol harus http/https' };
    const host = u.hostname;
    if (LOCALHOST_REGEX.test(host) || PRIVATE_IP_REGEX.test(host)) {
      return { safe: false, reason: 'URL tidak boleh localhost/private IP/metadata endpoint' };
    }
    if (host.includes('..') || host.length > 253) return { safe: false, reason: 'Hostname tidak valid' };
    return { safe: true };
  } catch {
    return { safe: false, reason: 'Format URL tidak valid' };
  }
}

export function validateSlug(slug, userRole = 'user') {
  if (!slug) return { valid: false, reason: 'Slug wajib diisi' };
  const lower = slug.toLowerCase();
  if (reservedSlugs.includes(lower)) return { valid: false, reason: 'Slug dilindungi sistem' };
  if (!/^[a-z0-9-_]+$/.test(lower)) return { valid: false, reason: 'Slug hanya boleh a-z, 0-9, -, _' };
  if (lower.length < 5) {
    if (userRole === 'superadmin') {
      // allowed with audit, but need reason
      return { valid: true, warning: 'Slug pendek (<5) memerlukan alasan & audit log (super admin)' , needsAudit: true };
    }
    return { valid: false, reason: 'Slug minimal 5 karakter. Super admin boleh 1-4 dengan audit.' };
  }
  if (lower.length > 50) return { valid: false, reason: 'Slug maksimal 50 karakter' };
  return { valid: true };
}

export function checkSlugUnique(slug, workspaceId, excludeId = null, collections = []) {
  const lower = slug.toLowerCase();
  for (const col of collections) {
    const items = storage.get(col, []);
    const found = items.find(i => i.slug?.toLowerCase() === lower && i.workspace_id === workspaceId && i.id !== excludeId);
    if (found) return { unique: false, existing: found };
  }
  return { unique: true };
}

export function suggestSlugs(base) {
  const clean = base.toLowerCase().replace(/[^a-z0-9-_]/g,'-').slice(0,20);
  return [
    `${clean}-${Math.floor(Math.random()*99)+1}`,
    `${clean}-${Math.random().toString(36).slice(2,5)}`,
    `${clean}${Date.now().toString(36).slice(-3)}`
  ];
}

// Password hash simulation - prod must use Argon2id
export function hashPassword(pwd) {
  // simple simulation: not secure, for demo only
  let h = 0;
  for (let i=0;i<pwd.length;i++) h = (h*31 + pwd.charCodeAt(i)) & 0xffffffff;
  return 'demo$' + h.toString(16) + '$' + btoa(pwd).slice(0,10);
}

export function verifyPassword(pwd, hash) {
  if (!hash?.startsWith('demo$')) return false;
  return hashPassword(pwd) === hash;
}

// Session handling with version + idle timeout
export function createShareSession(resourceType, resourceId) {
  const token = 'sess_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
  const session = {
    id: token,
    token,
    resource_type: resourceType,
    resource_id: resourceId,
    session_version: storage.get('session_version_'+resourceType+'_'+resourceId, 1),
    last_activity_at: Date.now(),
    created_at: Date.now()
  };
  const sessions = storage.get('share_sessions', []);
  sessions.push(session);
  storage.set('share_sessions', sessions);
  return session;
}

export function validateShareSession(token, resourceType, resourceId, idleSeconds = 60) {
  const sessions = storage.get('share_sessions', []);
  const sess = sessions.find(s => s.token === token && s.resource_type === resourceType && s.resource_id === resourceId);
  if (!sess) return { valid: false, reason: 'Sesi tidak ditemukan' };
  const currentVersion = storage.get('session_version_'+resourceType+'_'+resourceId, 1);
  if (sess.session_version !== currentVersion) return { valid: false, reason: 'Sesi telah dicabut' };
  const idleMs = idleSeconds * 1000;
  if (Date.now() - sess.last_activity_at > idleMs) return { valid: false, reason: 'Sesi berakhir karena tidak aktif 60 detik' };
  // update activity
  sess.last_activity_at = Date.now();
  storage.set('share_sessions', sessions);
  return { valid: true, session: sess };
}

export function revokeAllSessions(resourceType, resourceId) {
  const key = 'session_version_'+resourceType+'_'+resourceId;
  const cur = storage.get(key, 1);
  storage.set(key, cur+1);
  addAuditLog('revoke_sessions', `${resourceType}:${resourceId}`, { resourceType, resourceId, oldVersion: cur, newVersion: cur+1 });
  return cur+1;
}

// Rate limit simulation
const attemptMap = new Map();
export function checkRateLimit(key, maxAttempts = 5, windowSeconds = 60) {
  const now = Date.now();
  const arr = attemptMap.get(key) || [];
  const recent = arr.filter(t => now - t < windowSeconds*1000);
  if (recent.length >= maxAttempts) {
    return { allowed: false, retryAfter: Math.ceil((recent[0] + windowSeconds*1000 - now)/1000) };
  }
  recent.push(now);
  attemptMap.set(key, recent);
  return { allowed: true };
}

export function resetRateLimit(key) { attemptMap.delete(key); }
