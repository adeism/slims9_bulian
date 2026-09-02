// Auth - Demo Mode no password, Production Mode password required
import { storage, generateId, nowISO } from './storage.js';
import { featureFlags } from './config.js';
import { hashPassword, verifyPassword } from './security.js';

export function getCurrentUser() { return storage.getCurrentUser(); }

export function isDemoMode() {
  return featureFlags.demoMode.enabled;
}

export function loginDemo(user) {
  // user = {id, name, role, workspace_type, avatar}
  const fullUser = {
    ...user,
    login_at: nowISO(),
    mode: 'demo',
    is_superadmin: user.role === 'superadmin'
  };
  storage.setCurrentUser(fullUser);
  return fullUser;
}

export function loginProduction(email, password) {
  // For MVP, check against stored users or simple check
  const users = storage.get('prod_users', []);
  const found = users.find(u => u.email === email);
  if (!found) return { success: false, reason: 'User tidak ditemukan' };
  if (!verifyPassword(password, found.password_hash)) return { success: false, reason: 'Password salah' };
  const fullUser = { ...found, login_at: nowISO(), mode: 'production' };
  storage.setCurrentUser(fullUser);
  return { success: true, user: fullUser };
}

export function registerProduction({ name, email, password, role = 'personal' }) {
  const users = storage.get('prod_users', []);
  if (users.find(u => u.email === email)) return { success: false, reason: 'Email sudah terdaftar' };
  const newUser = {
    id: generateId(),
    name,
    email,
    password_hash: hashPassword(password),
    role,
    created_at: nowISO()
  };
  users.push(newUser);
  storage.set('prod_users', users);
  return { success: true, user: newUser };
}

export function logout() {
  storage.remove('current_user');
}

export function requireAuth() {
  const user = getCurrentUser();
  if (!user) {
    return { authed: false, redirect: '#/setup' };
  }
  return { authed: true, user };
}

export function hasPermission(perm) {
  const user = getCurrentUser();
  if (!user) return false;
  if (user.is_superadmin || user.role === 'superadmin') return true;
  // Simplified: personal user has all own perms, workspace role check would be more granular
  const rolePerms = {
    owner: ['*'],
    admin: ['link.*','microsite.*','form.*','qr.*','analytics.view','workspace.manage_members'],
    editor: ['link.create','link.update','microsite.update','form.update','qr.create','analytics.view'],
    contributor: ['link.create','microsite.create','form.create'],
    viewer: ['analytics.view']
  };
  const perms = rolePerms[user.workspace_role || 'owner'] || ['*'];
  if (perms.includes('*')) return true;
  if (perms.includes(perm)) return true;
  if (perms.includes(perm.split('.')[0] + '.*')) return true;
  return false;
}
