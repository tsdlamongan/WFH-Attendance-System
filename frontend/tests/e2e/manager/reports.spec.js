import { test, expect } from '../fixtures/auth.js';

test.describe('Manager Reports', () => {
  test('daily attendance report renders', async ({ managerPage }) => {
    await managerPage.goto('/manager/daily-attendance-report');
    await expect(managerPage.locator('h1').first()).toBeVisible();
    // Should have a date input
    await expect(managerPage.locator('input[type="date"]').first()).toBeVisible();
  });

  test('monthly attendance report renders', async ({ managerPage }) => {
    await managerPage.goto('/manager/monthly-attendance-report');
    await expect(managerPage.locator('h1').first()).toBeVisible();
  });

  test('check-in time report renders', async ({ managerPage }) => {
    await managerPage.goto('/manager/check-in-time-report');
    await expect(managerPage.locator('h1').first()).toBeVisible();
  });
});
