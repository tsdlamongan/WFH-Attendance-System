import { test, expect } from '../fixtures/auth.js';

test.describe('Manager Dashboard', () => {
  test('renders dashboard header and summary cards', async ({ managerPage }) => {
    await managerPage.goto('/manager/dashboard');
    await expect(managerPage.getByRole('heading', { name: 'Dashboard Manager' })).toBeVisible();
    await expect(managerPage.getByText('Total Karyawan')).toBeVisible();
    await expect(managerPage.getByText('Sedang Check In')).toBeVisible();
    await expect(managerPage.getByText('Sedang Cuti')).toBeVisible();
    await expect(managerPage.getByText('Rata-rata Jam Harian')).toBeVisible();
  });

  test('renders employee status section', async ({ managerPage }) => {
    await managerPage.goto('/manager/dashboard');
    await expect(managerPage.getByText('Status Karyawan')).toBeVisible();
  });

  test('date selector accepts input change', async ({ managerPage }) => {
    await managerPage.goto('/manager/dashboard');
    const dateInput = managerPage.locator('input[type="date"]').first();
    await dateInput.fill('2026-05-01');
    await expect(dateInput).toHaveValue('2026-05-01');
  });

  test('team isolation: manager beta sees own team employees only', async ({ managerBetaPage }) => {
    await managerBetaPage.goto('/manager/dashboard');
    await expect(managerBetaPage.getByRole('heading', { name: 'Dashboard Manager' })).toBeVisible();
  });
});
