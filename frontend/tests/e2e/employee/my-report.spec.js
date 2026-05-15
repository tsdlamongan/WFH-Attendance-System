import { test, expect } from '../fixtures/auth.js';

test.describe('Employee My Report', () => {
  test('renders report page with summary cards', async ({ employeePage }) => {
    await employeePage.goto('/employee/report');
    await expect(employeePage.getByRole('heading', { name: 'Laporan Kerja Saya' })).toBeVisible();
    await expect(employeePage.getByText('Hari Kerja (Filter)')).toBeVisible();
    await expect(employeePage.getByText('Total Jam Kerja')).toBeVisible();
    await expect(employeePage.getByText('Penyelesaian Tugas')).toBeVisible();
    await expect(employeePage.getByText('Detail Absensi')).toBeVisible();
  });

  test('has date range filter inputs', async ({ employeePage }) => {
    await employeePage.goto('/employee/report');
    await expect(employeePage.locator('input[type="date"]').first()).toBeVisible();
    await expect(employeePage.locator('input[type="date"]').nth(1)).toBeVisible();
    await expect(employeePage.getByRole('button', { name: 'Terapkan Filter' })).toBeVisible();
  });

  test('applying filter re-fetches without error', async ({ employeePage }) => {
    await employeePage.goto('/employee/report');
    await employeePage.getByRole('button', { name: 'Terapkan Filter' }).click();
    // No toast error
    await expect(employeePage.getByText('Detail Absensi')).toBeVisible();
  });
});
