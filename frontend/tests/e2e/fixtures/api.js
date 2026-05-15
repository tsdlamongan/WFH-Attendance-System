import { request } from '@playwright/test';

const API_BASE = process.env.E2E_API_BASE || 'http://127.0.0.1:8001/api/v1';

export async function login(email, password = 'Password1!') {
  const ctx = await request.newContext();
  const res = await ctx.post(`${API_BASE}/auth/login`, {
    data: { email, password, captcha_token: 'e2e-test-token' },
  });
  if (!res.ok()) {
    const body = await res.text();
    throw new Error(`Login failed for ${email}: ${res.status()} ${body}`);
  }
  const json = await res.json();
  await ctx.dispose();
  return { user: json.data.user, token: json.data.token };
}

export async function apiAs(token) {
  const ctx = await request.newContext({
    extraHTTPHeaders: { Authorization: `Bearer ${token}` },
  });
  return ctx;
}

export async function callApi(token, method, path, data) {
  const ctx = await apiAs(token);
  const url = `${API_BASE}${path.startsWith('/') ? path : '/' + path}`;
  const res = await ctx[method.toLowerCase()](url, data !== undefined ? { data } : undefined);
  const text = await res.text();
  await ctx.dispose();
  let body = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    body = text;
  }
  return { status: res.status(), body, ok: res.ok() };
}

export { API_BASE };
