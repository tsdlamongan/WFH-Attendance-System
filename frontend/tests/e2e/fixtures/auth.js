import { test as base, expect } from '@playwright/test';
import { resolve } from 'node:path';

const authDir = resolve(import.meta.dirname, '..', '.auth');

export const test = base.extend({
  superAdminPage: async ({ browser }, use) => {
    const ctx = await browser.newContext({ storageState: resolve(authDir, 'super-admin.json') });
    const page = await ctx.newPage();
    await use(page);
    await ctx.close();
  },
  managerPage: async ({ browser }, use) => {
    const ctx = await browser.newContext({ storageState: resolve(authDir, 'manager-alpha.json') });
    const page = await ctx.newPage();
    await use(page);
    await ctx.close();
  },
  managerBetaPage: async ({ browser }, use) => {
    const ctx = await browser.newContext({ storageState: resolve(authDir, 'manager-beta.json') });
    const page = await ctx.newPage();
    await use(page);
    await ctx.close();
  },
  employeePage: async ({ browser }, use) => {
    const ctx = await browser.newContext({ storageState: resolve(authDir, 'employee-alpha1.json') });
    const page = await ctx.newPage();
    await use(page);
    await ctx.close();
  },
  employeeAlpha2Page: async ({ browser }, use) => {
    const ctx = await browser.newContext({ storageState: resolve(authDir, 'employee-alpha2.json') });
    const page = await ctx.newPage();
    await use(page);
    await ctx.close();
  },
  employeeBetaPage: async ({ browser }, use) => {
    const ctx = await browser.newContext({ storageState: resolve(authDir, 'employee-beta1.json') });
    const page = await ctx.newPage();
    await use(page);
    await ctx.close();
  },
});

export { expect };
