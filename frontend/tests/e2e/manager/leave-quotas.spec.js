import { test, expect } from '../fixtures/auth.js';

test.describe('Manager Leave Quotas', () => {
  test('renders leave quotas page', async ({ managerPage }) => {
    await managerPage.goto('/manager/leave-quotas');
    // Should show a heading or table
    await expect(managerPage.locator('h1').first()).toBeVisible();
  });

  test('shows list of users with quotas', async ({ managerPage }) => {
    await managerPage.goto('/manager/leave-quotas');
    // Just verify the manager.alpha team users are listed
    await expect(managerPage.locator('body')).toContainText('employee.alpha1@e2e.test', { timeout: 10_000 });
  });
});
