import axios from 'axios';

const API_URL = process.env.VUE_APP_API_URL || 'http://127.0.0.1:8892/index.php';
const JWT_KEY = 'spoticlone_jwt';

const http = axios.create({
  baseURL: API_URL,
  withCredentials: true, // send the session cookie for web auth
  headers: { 'Content-Type': 'application/json' }
});

// Attach the bearer token (mobile / dev) on every request.
http.interceptors.request.use((config) => {
  const token = localStorage.getItem(JWT_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// A 401 on these is an expected answer (startup probes, failed login), not an expired session.
const AUTH_ACTIONS = new Set(['login', 'logout', 'check_auth', 'me']);
let unauthorizedHandler = null;
/** Register what to do when the session/JWT stops being valid mid-use (router: go to login). */
export const onUnauthorized = (fn) => { unauthorizedHandler = fn; };

let csrfToken = null;
export const setCsrfToken = (t) => { csrfToken = t; };
export const getJwt = () => localStorage.getItem(JWT_KEY);
export const setJwt = (t) => {
  if (t) localStorage.setItem(JWT_KEY, t);
  else localStorage.removeItem(JWT_KEY);
};

/**
 * Resolve a backend-relative URL (e.g. "/stream.php?token=...") to an absolute one.
 * On the web the API is same-origin (proxy /api -> backend), so a relative path works as-is.
 * On native (Capacitor) the API base is an absolute https URL, so we prepend its origin.
 */
export function resolveUrl(path) {
  if (!path) return path;
  if (/^https?:\/\//i.test(path)) return path;
  if (/^https?:\/\//i.test(API_URL)) {
    try {
      return new URL(path, new URL(API_URL).origin).href;
    } catch (e) {
      return path;
    }
  }
  return path; // same-origin web dev
}

/**
 * Call a backend action. Returns the `data` payload on success, throws on error.
 */
export async function apiCall(action, payload = {}) {
  const body = { action, ...payload };
  if (csrfToken) body.csrf_token = csrfToken;

  let data;
  try {
    ({ data } = await http.post('', body));
  } catch (e) {
    const status = e.response?.status;
    if (status === 401 && !AUTH_ACTIONS.has(action)) unauthorizedHandler?.();
    // 429 (límite de nginx) y 415 son errores normales: nunca mandan al login
    else if (status === 429) e.message = 'Demasiadas peticiones, espera un momento';
    throw e;
  }
  if (data.status !== 'success') {
    const err = new Error(data.message || 'Error en la petición');
    err.response = data;
    throw err;
  }
  return data.data;
}

export default { apiCall, setCsrfToken, setJwt, getJwt, resolveUrl, onUnauthorized };
