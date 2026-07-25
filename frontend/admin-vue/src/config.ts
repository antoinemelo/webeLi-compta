function meta(name: string, fallback = ''): string {
  return document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)?.content || fallback;
}

function normalizedPath(value: string, fallback: string): string {
  const path = value.trim() || fallback;
  const normalized = `/${path.replace(/^\/+|\/+$/g, '')}`;
  return normalized === '/' ? '' : normalized;
}

export const runtimeConfig = {
  baseUrl: normalizedPath(meta('compta-base-url'), ''),
  appBaseUrl: normalizedPath(meta('compta-app-base-url'), '/app'),
  apiBaseUrl: normalizedPath(meta('compta-api-base-url'), '/api/v1'),
  loginUrl: meta('compta-login-url', '/login'),
  logoutUrl: meta('compta-logout-url', '/logout'),
  legacyUrl: meta('compta-legacy-url', '/?legacy=1')
};
