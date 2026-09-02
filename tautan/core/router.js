// Simple hash router
export class Router {
  constructor(routes) {
    this.routes = routes;
    this.current = null;
    window.addEventListener('hashchange', () => this.handle());
    window.addEventListener('load', () => this.handle());
  }
  handle() {
    const hash = window.location.hash || '#/overview';
    const path = hash.replace('#','') || '/overview';
    // match
    for (const r of this.routes) {
      const match = this.matchRoute(r.path, path);
      if (match) {
        this.current = { route: r, params: match.params, path };
        r.handler(match.params);
        return;
      }
    }
    // fallback
    const fallback = this.routes.find(r => r.path === '*');
    if (fallback) fallback.handler({});
  }
  matchRoute(routePath, actualPath) {
    if (routePath === '*') return { params: {} };
    const routeParts = routePath.split('/').filter(Boolean);
    const actualParts = actualPath.split('/').filter(Boolean);
    if (routeParts.length !== actualParts.length && !routePath.includes(':')) {
      if (routePath === actualPath) return { params: {} };
      // try prefix
      if (routePath === '/overview' && actualPath === '/') return { params: {} };
      return null;
    }
    const params = {};
    if (routeParts.length !== actualParts.length) return null;
    for (let i=0;i<routeParts.length;i++) {
      const rp = routeParts[i];
      const ap = actualParts[i];
      if (rp.startsWith(':')) {
        params[rp.slice(1)] = ap;
      } else if (rp !== ap) {
        return null;
      }
    }
    return { params };
  }
  navigate(path) {
    window.location.hash = '#' + path;
  }
}
