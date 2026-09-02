// Unified Resource Resolver - Strategy A prefix for MVP
import { storage } from './storage.js';
import { validateSlug, checkSlugUnique, isSafeUrl } from './security.js';

export function resolvePublicPath(path) {
  // path like /abcde, /m/abcde, /f/abcde, /qr/xxx, /p/xxx
  const clean = path.replace(/^\/+/, '');
  const parts = clean.split('/');
  if (parts.length === 1 && parts[0]) {
    // shortlink
    return { type: 'link', slug: parts[0], strategy: 'A' };
  }
  if (parts[0] === 'm' && parts[1]) {
    return { type: 'microsite', slug: parts[1], strategy: 'A' };
  }
  if (parts[0] === 'f' && parts[1]) {
    return { type: 'form', slug: parts[1], strategy: 'A' };
  }
  if (parts[0] === 'qr' && parts[1]) {
    return { type: 'qr', id: parts[1], strategy: 'A' };
  }
  if (parts[0] === 'p' && parts[1]) {
    return { type: 'secure', slug: parts[1], strategy: 'A' };
  }
  if (parts[0] === 'embed' && parts[1] === 'f' && parts[2]) {
    return { type: 'embed_form', slug: parts[2], strategy: 'A' };
  }
  return { type: 'unknown' };
}

export function findResource(type, slug, workspaceId = null) {
  let collectionKey;
  if (type === 'link') collectionKey = 'links';
  else if (type === 'microsite') collectionKey = 'microsites';
  else if (type === 'form') collectionKey = 'forms';
  else if (type === 'campaign') collectionKey = 'campaigns';
  else return null;

  const items = storage.get(collectionKey, []);
  return items.find(i => i.slug?.toLowerCase() === slug.toLowerCase() && (!workspaceId || i.workspace_id === workspaceId));
}

export function canAccessPublic(resource) {
  if (!resource) return { can: false, reason: 'Not found' };
  if (resource.status === 'draft') return { can: false, reason: 'Draft tidak dapat diakses publik' };
  if (resource.settings?.accessExpiresAt) {
    const exp = new Date(resource.settings.accessExpiresAt);
    if (new Date() > exp) return { can: false, reason: 'Akses telah berakhir' };
  }
  if (resource.settings?.pause) return { can: false, reason: 'Link di-pause' };
  return { can: true };
}
