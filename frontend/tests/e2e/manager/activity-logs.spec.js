import { test, expect } from '../fixtures/auth.js';

test.describe('Manager Activity Logs', () => {
  test('renders activity logs page', async ({ managerPage }) => {
    await managerPage.goto('/manager/activity-logs');
    await expect(managerPage.getByRole('heading', { name: 'Log Aktivitas' })).toBeVisible();
    await expect(managerPage.getByRole('heading', { name: 'Filter' })).toBeVisible();
  });

  test('has filter controls', async ({ managerPage }) => {
    await managerPage.goto('/manager/activity-logs');
    await expect(managerPage.getByRole('button', { name: 'Terapkan Filter' })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: 'Hapus Filter' })).toBeVisible();
  });

  test('applies and resets filters', async ({ managerPage }) => {
    await managerPage.goto('/manager/activity-logs');
    await managerPage.getByRole('button', { name: 'Terapkan Filter' }).click();
    await managerPage.waitForTimeout(500);
    await managerPage.getByRole('button', { name: 'Hapus Filter' }).click();
    await managerPage.waitForTimeout(500);
  });
});
