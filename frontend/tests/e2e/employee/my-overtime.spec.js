import { test, expect } from '../fixtures/auth.js';

const REASON = 'E2E lembur menyelesaikan laporan bulanan';

// Serial: the later tests build on the pending request created by "submits valid overtime request".
test.describe.serial('Employee My Overtime', () => {
  test('renders page with sidebar link', async ({ employeePage }) => {
    await employeePage.goto('/employee/dashboard');
    await employeePage.getByRole('link', { name: 'Pengajuan Lembur' }).click();
    await expect(employeePage).toHaveURL(/\/employee\/overtime$/);
    await expect(employeePage.getByRole('heading', { name: 'Pengajuan Lembur' })).toBeVisible();
    await expect(employeePage.getByRole('button', { name: /Ajukan Lembur/ }).first()).toBeVisible();
  });

  test('opens request modal with reason and duration fields', async ({ employeePage }) => {
    await employeePage.goto('/employee/overtime');
    await employeePage.getByRole('button', { name: /Ajukan Lembur/ }).first().click();
    await expect(employeePage.getByText('Informasi Pengajuan Lembur')).toBeVisible();
    await expect(employeePage.getByLabel(/Alasan lembur/)).toBeVisible();
    await expect(employeePage.getByLabel(/Durasi lembur/)).toHaveValue('60');
  });

  test('duration select offers 15-minute steps up to 8 hours', async ({ employeePage }) => {
    await employeePage.goto('/employee/overtime');
    await employeePage.getByRole('button', { name: /Ajukan Lembur/ }).first().click();
    const options = employeePage.getByLabel(/Durasi lembur/).locator('option');
    await expect(options).toHaveCount(32);
    await expect(options.first()).toHaveText('15 menit');
    await expect(options.first()).toHaveAttribute('value', '15');
    await expect(options.last()).toHaveText('8 jam');
    await expect(options.last()).toHaveAttribute('value', '480');
  });

  test('rejects reason shorter than 5 characters', async ({ employeePage }) => {
    await employeePage.goto('/employee/overtime');
    await employeePage.getByRole('button', { name: /Ajukan Lembur/ }).first().click();
    await employeePage.getByLabel(/Alasan lembur/).fill('Abc');
    // Bypass HTML5 minLength so JS validation in handleSubmit runs
    await employeePage.locator('form').evaluate((form) => { form.noValidate = true; });
    await employeePage.getByRole('button', { name: /Kirim Pengajuan/ }).click();
    await expect(employeePage.getByText('Alasan harus minimal 5 karakter')).toBeVisible();
  });

  test('submits valid overtime request', async ({ employeeAlpha2Page }) => {
    await employeeAlpha2Page.goto('/employee/overtime');
    await employeeAlpha2Page.getByRole('button', { name: /Ajukan Lembur/ }).first().click();
    await employeeAlpha2Page.getByLabel(/Alasan lembur/).fill(REASON);
    await employeeAlpha2Page.getByLabel(/Durasi lembur/).selectOption('90');
    await employeeAlpha2Page.getByRole('button', { name: /Kirim Pengajuan/ }).click();
    // Modal closes, list shows the entry with a Menunggu badge
    await expect(employeeAlpha2Page.getByText(REASON)).toBeVisible({ timeout: 10_000 });
    await expect(employeeAlpha2Page.getByText('Menunggu', { exact: true })).toBeVisible();
    await expect(employeeAlpha2Page.getByText('1 jam 30 menit')).toBeVisible();
  });

  test('cannot request overtime twice on the same day', async ({ employeeAlpha2Page }) => {
    await employeeAlpha2Page.goto('/employee/overtime');
    await employeeAlpha2Page.getByRole('button', { name: /Ajukan Lembur/ }).first().click();
    await employeeAlpha2Page.getByLabel(/Alasan lembur/).fill('Pengajuan kedua pada hari yang sama');
    await employeeAlpha2Page.getByRole('button', { name: /Kirim Pengajuan/ }).click();
    // Backend rule: one non-rejected overtime record per day; its message is surfaced as a toast
    await expect(employeeAlpha2Page.getByText(/sudah memiliki pengajuan lembur/)).toBeVisible();
  });

  test('dashboard shows the pending overtime banner', async ({ employeeAlpha2Page }) => {
    await employeeAlpha2Page.goto('/employee/dashboard');
    await expect(employeeAlpha2Page.getByText('Menunggu persetujuan manager')).toBeVisible({ timeout: 10_000 });
    await expect(employeeAlpha2Page.getByText(/Durasi diajukan 1 jam 30 menit/)).toBeVisible();
  });
});
