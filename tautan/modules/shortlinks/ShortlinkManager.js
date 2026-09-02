import { storage, generateId, nowISO, addAuditLog } from '../../core/storage.js';
import { validateSlug, isSafeUrl, checkSlugUnique, suggestSlugs } from '../../core/security.js';
import { generateSlug } from '../../shared/utils/slug.js';

export class ShortlinkManager {
  static create({ workspace_id, project_id, slug, target_url, title, settings = {}, userRole = 'user', reason = '' }) {
    // Validate URL safe
    const safe = isSafeUrl(target_url);
    if (!safe.safe) return { success: false, reason: safe.reason };
    // Validate slug
    const v = validateSlug(slug, userRole);
    if (!v.valid) return { success: false, reason: v.reason };
    if (v.needsAudit && !reason) return { success: false, reason: 'Slug pendek butuh alasan (super admin)' };
    // Unique
    const uniq = checkSlugUnique(slug, workspace_id, null, ['links','microsites','forms']);
    if (!uniq.unique) return { success: false, reason: 'Slug sudah dipakai', suggestions: suggestSlugs(slug) };
    const links = storage.getLinks();
    const newLink = {
      id: generateId(),
      workspace_id,
      project_id: project_id || null,
      slug: slug.toLowerCase(),
      target_url,
      title: title || slug,
      clicks: 0,
      settings: { redirectType: 302, ...settings },
      status: 'active',
      created_at: nowISO(),
      updated_at: nowISO()
    };
    links.unshift(newLink);
    storage.setLinks(links);
    if (v.needsAudit) {
      addAuditLog('override_slug', `link:${newLink.id}`, { slug, reason, userRole });
    } else {
      addAuditLog('link.create', `link:${newLink.id}`, { slug });
    }
    return { success: true, data: newLink };
  }

  static update(id, patch) {
    const links = storage.getLinks();
    const idx = links.findIndex(l=>l.id===id);
    if (idx===-1) return { success: false, reason: 'Not found' };
    links[idx] = { ...links[idx], ...patch, updated_at: nowISO() };
    storage.setLinks(links);
    addAuditLog('link.update', `link:${id}`, patch);
    return { success: true, data: links[idx] };
  }

  static delete(id) {
    let links = storage.getLinks();
    links = links.filter(l=>l.id!==id);
    storage.setLinks(links);
    addAuditLog('link.delete', `link:${id}`, {});
    return { success: true };
  }

  static list(workspace_id) {
    return storage.getLinks().filter(l=>!workspace_id || l.workspace_id===workspace_id);
  }

  static incrementClick(id) {
    const links = storage.getLinks();
    const idx = links.findIndex(l=>l.id===id);
    if (idx!==-1) {
      links[idx].clicks = (links[idx].clicks||0)+1;
      storage.setLinks(links);
    }
  }
}
