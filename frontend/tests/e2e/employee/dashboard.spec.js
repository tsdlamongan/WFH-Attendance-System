import { test, expect } from '../fixtures/auth.js';

test.describe('Employee Dashboard', () => {
  test('renders dashboard with status cards', async ({ employeePage }) => {
    await employeePage.goto('/employee/dashboard');
    await expect(employeePage.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
    await expect(employeePage.getByText('Status Saat Ini')).toBeVisible();
    await expect(employeePage.getByText('Jam Kerja Hari Ini')).toBeVisible();
    await expect(employeePage.getByText('Jam Tersisa')).toBeVisible();
    await expect(employeePage.getByText('Progress Hari Ini')).toBeVisible();
  });

  test('shows Check In button when not checked in', async ({ employeePage }) => {
    await employeePage.goto('/employee/dashboard');
    await expect(employeePage.getByRole('button', { name: /^Check In$/ }).first()).toBeVisible();
  });

  test('opens check-in modal and submits with one task', async ({ employeeAlpha2Page }) => {
    await employeeAlpha2Page.goto('/employee/dashboard');
    await employeeAlpha2Page.getByRole('button', { name: /^Check In$/ }).first().click();
    await expect(employeeAlpha2Page.getByText('Tambahkan tugas yang akan Anda kerjakan hari ini')).toBeVisible();
    await employeeAlpha2Page.getByPlaceholder('Tugas 1').fill('E2E task 1');
    // Modal submit button
    await employeeAlpha2Page.locator('form').getByRole('button', { name: /^Check In$/ }).click();
    await expect(employeeAlpha2Page.getByText('Sudah Check In')).toBeVisible({ timeout: 10_000 });
  });

  test('can add multiple tasks before check-in', async ({ employeeBetaPage }) => {
    await employeeBetaPage.goto('/employee/dashboard');
    await employeeBetaPage.getByRole('button', { name: /^Check In$/ }).first().click();
    await employeeBetaPage.getByPlaceholder('Tugas 1').fill('Task A');
    await employeeBetaPage.getByRole('button', { name: /Tambah Tugas/ }).click();
    await employeeBetaPage.getByPlaceholder('Tugas 2').fill('Task B');
    await expect(employeeBetaPage.getByPlaceholder('Tugas 2')).toHaveValue('Task B');
    await employeeBetaPage.getByRole('button', { name: 'Batal' }).click();
  });

  test('standby checkbox toggles task list', async ({ browser }) => {
    // Use a fresh page from employee.beta1 storage but only test UI interaction
    const ctx = await browser.newContext({ storageState: 'tests/e2e/.auth/employee-beta1.json' });
    const page = await ctx.newPage();
    try {
      await page.goto('/employee/dashboard');
      // Wait for the dashboard data to finish loading before branching on status
      await expect(page.getByText('Status Saat Ini')).toBeVisible({ timeout: 10_000 });
      const isCheckedOut = await page.getByText('Belum Check In').isVisible().catch(() => false);
      if (!isCheckedOut) {
        // Already checked in, skip the modal test
        return;
      }
      await page.getByRole('button', { name: /^Check In$/ }).first().click();
      const standbyCheckbox = page.getByLabel(/Standby/);
      await standbyCheckbox.check();
      await expect(page.getByPlaceholder('Tugas 1')).toHaveValue('Standby');
      await page.getByRole('button', { name: 'Batal' }).click();
    } finally {
      await ctx.close();
    }
  });

  test('shows current session after check-in including tasks', async ({ employeePage }) => {
    await employeePage.goto('/employee/dashboard');
    // Wait for the dashboard data to finish loading before branching on status
    await expect(employeePage.getByText('Status Saat Ini')).toBeVisible({ timeout: 10_000 });
    // Already checked in from another test? Check status, do check-in if needed.
    const checkedIn = await employeePage.getByText('Sudah Check In').isVisible().catch(() => false);
    if (!checkedIn) {
      await employeePage.getByRole('button', { name: /^Check In$/ }).first().click();
      await employeePage.getByPlaceholder('Tugas 1').fill('Initial task');
      await employeePage.locator('form').getByRole('button', { name: /^Check In$/ }).click();
      await expect(employeePage.getByText('Sudah Check In')).toBeVisible({ timeout: 10_000 });
    }
    await expect(employeePage.getByText('Sesi Saat Ini')).toBeVisible();
    await expect(employeePage.getByText('Waktu Check In')).toBeVisible();
    await expect(employeePage.getByText('Tugas Hari Ini:')).toBeVisible();
  });

  test('check-out modal opens and marks tasks complete', async ({ employeePage }) => {
    await employeePage.goto('/employee/dashboard');
    // Wait for the dashboard data to finish loading before branching on status
    await expect(employeePage.getByText('Status Saat Ini')).toBeVisible({ timeout: 10_000 });
    const checkedIn = await employeePage.getByText('Sudah Check In').isVisible().catch(() => false);
    if (!checkedIn) {
      await employeePage.getByRole('button', { name: /^Check In$/ }).first().click();
      await employeePage.getByPlaceholder('Tugas 1').fill('Pre-checkout task');
      await employeePage.locator('form').getByRole('button', { name: /^Check In$/ }).click();
      await expect(employeePage.getByText('Sudah Check In')).toBeVisible({ timeout: 10_000 });
    }
    await employeePage.getByRole('button', { name: /^Check Out$/ }).first().click();
    await expect(employeePage.getByText(/Tandai status penyelesaian tugas/i)).toBeVisible();
    // Click first task checkbox to mark complete
    const checkboxes = employeePage.getByRole('checkbox');
    const count = await checkboxes.count();
    if (count > 0) {
      await checkboxes.first().check();
    }
    await employeePage.locator('form').getByRole('button', { name: /^Check Out$/ }).click();
    await expect(employeePage.getByText('Belum Check In')).toBeVisible({ timeout: 10_000 });
  });
});
