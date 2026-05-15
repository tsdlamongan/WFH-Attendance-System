import { test, expect } from '../fixtures/auth.js';

test.describe('Super Admin Impersonation', () => {
  test('impersonates an employee and redirects to employee dashboard', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/users');
    const row = superAdminPage.getByRole('row').filter({ hasText: 'employee.alpha1@e2e.test' });
    // Accept confirm dialog
    superAdminPage.once('dialog', (d) => d.accept());
    await row.getByTitle('Impersonate').click();
    await superAdminPage.waitForTimeout(2000);
    // Should land somewhere appropriate (employee dashboard)
    await expect(superAdminPage).toHaveURL(/employee|dashboard|\/$/);
  });

  test('cannot impersonate a disabled user shows error', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/users');
    const row = superAdminPage.getByRole('row').filter({ hasText: 'disabled.alpha@e2e.test' });
    const impersonateBtn = row.getByTitle('Impersonate');
    if (await impersonateBtn.count()) {
      superAdminPage.once('dialog', (d) => d.accept());
      await impersonateBtn.click();
      await superAdminPage.waitForTimeout(1500);
      // Toast should appear with error
      await expect(superAdminPage.locator('body')).toContainText(/dinonaktifkan|disabled|tidak dapat/i);
    }
  });
});
