import { request } from '@playwright/test';

const API_BASE = process.env.E2E_API_BASE || 'http://127.0.0.1:10003/api/v1';

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

// ---------------------------------------------------------------------------
// Overtime (lembur) helpers. Seed state through the real API, as the user would.
// Backend rules these rely on: one non-rejected overtime record per employee per day (today),
// only pending records can be approved/rejected, and a rejected record frees the employee again.
// ---------------------------------------------------------------------------

/**
 * Makes sure the employee has a pending overtime request today and returns it (OvertimeResource).
 * Reuses an existing pending record; otherwise creates one. Throws when the employee is blocked
 * (e.g. already has an approved record today) so a spec fails with a clear message.
 */
export async function ensurePendingOvertime(email, { reason = 'E2E lembur menyelesaikan laporan', plannedMinutes = 60 } = {}) {
  const { token } = await login(email);
  const mine = await callApi(token, 'GET', '/overtimes/my-requests');
  const existing = (mine.body?.data ?? []).find((overtime) => overtime.status === 'pending');
  if (existing) return existing;

  const created = await callApi(token, 'POST', '/overtimes', { reason, planned_minutes: plannedMinutes });
  if (!created.ok) {
    throw new Error(`Could not create overtime request for ${email}: ${created.status} ${JSON.stringify(created.body)}`);
  }
  return created.body.data;
}

/** Manager-side list of the team's overtime records (OvertimeResource[]), optionally by status. */
export async function listOvertimesAs(managerEmail, status) {
  const { token } = await login(managerEmail);
  const query = status ? `?status=${status}` : '';
  const res = await callApi(token, 'GET', `/manager/overtimes${query}`);
  return res.body?.data ?? [];
}

/** Rejects every pending overtime request of the manager's team, giving specs a clean slate. */
export async function resolvePendingOvertimes(managerEmail, notes = 'E2E reset') {
  const { token } = await login(managerEmail);
  const pending = (await callApi(token, 'GET', '/manager/overtimes?status=pending')).body?.data ?? [];
  for (const overtime of pending) {
    await callApi(token, 'PUT', `/manager/overtimes/${overtime.id}/reject`, { notes });
  }
  return pending.length;
}

export { API_BASE };
