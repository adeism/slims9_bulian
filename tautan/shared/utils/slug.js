export function generateSlug(text = '', length = 5) {
  if (text) {
    const base = text.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,20);
    if (base.length >= 3) return base;
  }
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  let s = '';
  for (let i=0;i<length;i++) s += chars[Math.floor(Math.random()*chars.length)];
  return s;
}

export function slugify(text) {
  return text.toLowerCase().trim().replace(/[^a-z0-9\s-]/g,'').replace(/\s+/g,'-').replace(/-+/g,'-');
}
