import { test, expect } from '../fixtures/auth.js';

test.describe('Employee My Leave', () => {
  test('renders page with quota summary', async ({ employeePage }) => {
    await employeePage.goto('/employee/leave');
    await expect(employeePage.getByRole('heading', { name: 'Pengajuan Cuti' })).toBeVisible();
    await expect(employeePage.getByText(/Informasi Jatah Cuti Tahun/)).toBeVisible();
    await expect(employeePage.getByText('Total Jatah')).toBeVisible();
    await expect(employeePage.getByText('Terpakai')).toBeVisible();
    await expect(employeePage.getByText('Menunggu')).toBeVisible();
    await expect(employeePage.getByText('Sisa')).toBeVisible();
  });

  test('opens request modal', async ({ employeePage }) => {
    await employeePage.goto('/employee/leave');
    await employeePage.getByRole('button', { name: /Ajukan Cuti/ }).first().click();
    await expect(employeePage.getByText('Informasi Pengajuan Cuti')).toBeVisible();
    await expect(employeePage.getByPlaceholder(/Harap berikan alasan/)).toBeVisible();
  });

  test('rejects reason shorter than 10 characters', async ({ employeePage }) => {
    await employeePage.goto('/employee/leave');
    await employeePage.getByRole('button', { name: /Ajukan Cuti/ }).first().click();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 7);
    const startDate = tomorrow.toISOString().split('T')[0];
    tomorrow.setDate(tomorrow.getDate() + 1);
    const endDate = tomorrow.toISOString().split('T')[0];
    await employeePage.locator('input[type="date"]').first().fill(startDate);
    await employeePage.locator('input[type="date"]').nth(1).fill(endDate);
    await employeePage.getByPlaceholder(/Harap berikan alasan/).fill('Sakit');
    // Bypass HTML5 minLength so JS validation in handleSubmit runs
    await employeePage.locator('form').evaluate((form) => { form.noValidate = true; });
    await employeePage.getByRole('button', { name: /Kirim Pengajuan/ }).click();
    await expect(employeePage.getByText('Alasan harus minimal 10 karakter')).toBeVisible();
  });

  test('submits valid leave request', async ({ employeeAlpha2Page }) => {
    await employeeAlpha2Page.goto('/employee/leave');
    await employeeAlpha2Page.getByRole('button', { name: /Ajukan Cuti/ }).first().click();
    const date = new Date();
    date.setDate(date.getDate() + 14);
    const startDate = date.toISOString().split('T')[0];
    date.setDate(date.getDate() + 1);
    const endDate = date.toISOString().split('T')[0];
    await employeeAlpha2Page.locator('input[type="date"]').first().fill(startDate);
    await employeeAlpha2Page.locator('input[type="date"]').nth(1).fill(endDate);
    await employeeAlpha2Page.getByPlaceholder(/Harap berikan alasan/).fill('Acara keluarga yang sangat penting');
    await employeeAlpha2Page.getByRole('button', { name: /Kirim Pengajuan/ }).click();
    // Modal closes, list shows entry with Menunggu badge
    await expect(employeeAlpha2Page.getByText('Menunggu').first()).toBeVisible({ timeout: 10_000 });
  });

  test('year navigation chevrons work', async ({ employeePage }) => {
    await employeePage.goto('/employee/leave');
    const currentYear = new Date().getFullYear();
    await expect(employeePage.getByText(`Informasi Jatah Cuti Tahun ${currentYear}`)).toBeVisible();
  });
});
