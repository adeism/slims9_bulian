// SVG helpers - lightweight backgrounds
export function bgPattern() {
  return `<svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="dots" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r="1" fill="#e5e7eb" opacity="0.6"/></pattern></defs><rect width="100%" height="100%" fill="url(#dots)"/></svg>`;
}
export function logoSvg() {
  return `<svg viewBox="0 0 32 32" width="32" height="32" xmlns="http://www.w3.org/2000/svg"><rect width="32" height="32" rx="8" fill="#4f46e5"/><path d="M8 12 C8 8, 12 8, 16 12 C20 16, 24 16, 24 12" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round"/><circle cx="16" cy="20" r="3" fill="white"/></svg>`;
}
