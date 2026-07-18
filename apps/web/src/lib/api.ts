import axios from 'axios';

/**
 * Shared HTTP client for the cPorter Admin API.
 *
 * Admin auth is a Laravel **web-guard session with CSRF** (Sanctum SPA cookie auth), not a
 * bearer token: the session cookie rides on every request (`withCredentials`) and Laravel's
 * `XSRF-TOKEN` cookie is echoed back as the `X-XSRF-TOKEN` header on state-changing requests.
 * The XSRF-TOKEN cookie is set by any `web`-group response, so the app MUST issue a GET
 * (e.g. `/auth/user`, done by the auth bootstrap query) before any POST.
 *
 * Base URL defaults to same-origin `/api/v1` (Laravel serves the SPA in prod;
 * Vite proxies `/api` → localhost:8000 in dev).
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
  withCredentials: true,
  headers: { Accept: 'application/json' },
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
});
