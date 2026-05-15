import { test, expect } from '../fixtures/auth.js';

test.describe('Employee Change Password', () => {
  test('renders form fields', async ({ employeePage }) => {
    await employeePage.goto('/employee/change-password');
    await expect(employeePage.getByRole('heading', { name: 'Ganti Password' })).toBeVisible();
    await expect(employeePage.getByPlaceholder('Masukkan password lama')).toBeVisible();
    await expect(employeePage.getByPlaceholder(/Masukkan password baru/)).toBeVisible();
    await expect(employeePage.getByPlaceholder('Ulangi password baru')).toBeVisible();
    await expect(employeePage.getByRole('button', { name: /Ubah Password/ })).toBeVisible();
  });

  test('rejects mismatching confirmation', async ({ employeePage }) => {
    await employeePage.goto('/employee/change-password');
    await employeePage.getByPlaceholder('Masukkan password lama').fill('Password1!');
    await employeePage.getByPlaceholder(/Masukkan password baru/).fill('NewPass99@');
    await employeePage.getByPlaceholder('Ulangi password baru').fill('Different99@');
    await employeePage.getByRole('button', { name: /Ubah Password/ }).click();
    await expect(employeePage.getByText('Konfirmasi password tidak cocok')).toBeVisible();
  });

  test('rejects new password shorter than 8 chars', async ({ employeePage }) => {
    await employeePage.goto('/employee/change-password');
    await employeePage.getByPlaceholder('Masukkan password lama').fill('Password1!');
    await employeePage.getByPlaceholder(/Masukkan password baru/).fill('short');
    await employeePage.getByPlaceholder('Ulangi password baru').fill('short');
    await employeePage.getByRole('button', { name: /Ubah Password/ }).click();
    await expect(employeePage.getByText('Password baru minimal 8 karakter')).toBeVisible();
  });

  test('rejects wrong current password', async ({ employeeAlpha2Page }) => {
    await employeeAlpha2Page.goto('/employee/change-password');
    await employeeAlpha2Page.getByPlaceholder('Masukkan password lama').fill('WrongOld1!');
    await employeeAlpha2Page.getByPlaceholder(/Masukkan password baru/).fill('Password1!new');
    await employeeAlpha2Page.getByPlaceholder('Ulangi password baru').fill('Password1!new');
    await employeeAlpha2Page.getByRole('button', { name: /Ubah Password/ }).click();
    // Backend rejects, toast should appear
    await expect(employeeAlpha2Page.locator('body')).toContainText(/tidak sesuai|gagal|salah|invalid|incorrect/i, { timeout: 5000 });
  });
});
