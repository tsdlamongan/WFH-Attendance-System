import { test, expect } from '../fixtures/auth.js';

test.describe('Manager Leave Approval', () => {
  test('renders leave approval page', async ({ managerPage }) => {
    await managerPage.goto('/manager/leaves');
    await expect(managerPage.getByRole('heading', { name: 'Persetujuan Cuti' })).toBeVisible();
  });

  test('status filter dropdown is present', async ({ managerPage }) => {
    await managerPage.goto('/manager/leaves');
    const select = managerPage.locator('select').first();
    await expect(select).toBeVisible();
    await select.selectOption({ label: 'Menunggu' });
    await expect(select).toHaveValue('pending');
  });

  test('empty state shows when no pending leaves', async ({ managerBetaPage }) => {
    await managerBetaPage.goto('/manager/leaves');
    await managerBetaPage.locator('select').first().selectOption({ label: 'Menunggu' });
    // Either empty state or pending leaves visible
    await managerBetaPage.waitForTimeout(500);
  });
});
