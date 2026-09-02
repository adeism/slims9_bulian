export function required(v) { return v !== null && v !== undefined && String(v).trim() !== ''; }
export function isEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
export function isUrl(v) { try { new URL(v); return true; } catch { return false; } }
export function minLength(v,l) { return String(v).length >= l; }
export function maxLength(v,l) { return String(v).length <= l; }
