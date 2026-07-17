import axios from 'axios';

const TOKEN_KEY = 'cporter.token';

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null): void {
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else localStorage.removeItem(TOKEN_KEY);
}

/**
 * Shared HTTP client for the cPorter Deploy/Admin API.
 * Base URL defaults to same-origin `/api/v1` (Laravel serves the SPA in prod;
 * Vite proxies `/api` → localhost:8000 in dev).
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
  const token = getToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});
