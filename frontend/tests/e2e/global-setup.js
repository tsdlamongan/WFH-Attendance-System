import { chromium, request } from '@playwright/test';
import { mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';

const API_BASE = process.env.E2E_API_BASE || 'http://127.0.0.1:10003/api/v1';
const APP_BASE = 'http://localhost:10004';

export const E2E_USERS = {
  superAdmin: {
    email: 'superadmin@e2e.test',
    password: 'Password1!',
    role: 'super_admin',
    statePath: resolve(import.meta.dirname, '.auth/super-admin.json'),
  },
  managerAlpha: {
    email: 'manager.alpha@e2e.test',
    password: 'Password1!',
    role: 'manager',
    statePath: resolve(import.meta.dirname, '.auth/manager-alpha.json'),
  },
  managerBeta: {
    email: 'manager.beta@e2e.test',
    password: 'Password1!',
    role: 'manager',
    statePath: resolve(import.meta.dirname, '.auth/manager-beta.json'),
  },
  employeeAlpha1: {
    email: 'employee.alpha1@e2e.test',
    password: 'Password1!',
    role: 'employee',
    statePath: resolve(import.meta.dirname, '.auth/employee-alpha1.json'),
  },
  employeeAlpha2: {
    email: 'employee.alpha2@e2e.test',
    password: 'Password1!',
    role: 'employee',
    statePath: resolve(import.meta.dirname, '.auth/employee-alpha2.json'),
  },
  employeeBeta1: {
    email: 'employee.beta1@e2e.test',
    password: 'Password1!',
    role: 'employee',
    statePath: resolve(import.meta.dirname, '.auth/employee-beta1.json'),
  },
};

async function loginViaApi(api, email, password) {
  const res = await api.post(`${API_BASE}/auth/login`, {
    data: { email, password, captcha_token: 'e2e-test-token' },
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  });
  if (!res.ok()) {
    const body = await res.text();
    throw new Error(`Login failed for ${email}: ${res.status()} ${body}`);
  }
  const text = await res.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    throw new Error(`Login non-JSON response for ${email}: ${res.status()} ${text.slice(0, 200)}`);
  }
  return { user: json.data.user, token: json.data.token };
}

async function saveStorageState(browser, statePath, user, token) {
  mkdirSync(dirname(statePath), { recursive: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  // Visit landing page first so localStorage scope matches app origin
  await page.goto(APP_BASE);
  await page.evaluate(({ user, token }) => {
    localStorage.setItem('user', JSON.stringify(user));
    localStorage.setItem('token', token);
  }, { user, token });
  await context.storageState({ path: statePath });
  await context.close();
}

export default async function globalSetup() {
  const api = await request.newContext();
  const browser = await chromium.launch();

  try {
    for (const key of Object.keys(E2E_USERS)) {
      const u = E2E_USERS[key];
      const { user, token } = await loginViaApi(api, u.email, u.password);
      await saveStorageState(browser, u.statePath, user, token);
    }
  } finally {
    await api.dispose();
    await browser.close();
  }
}
