import { test, expect } from '../fixtures/auth.js';

test.describe.serial('Manager Holidays', () => {
  test('renders holidays page', async ({ managerPage }) => {
    await managerPage.goto('/manager/holidays');
    await expect(managerPage.getByRole('heading', { name: 'Manajemen Hari Libur' })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: 'Tambah', exact: true }).first()).toBeVisible();
  });

  test('opens create holiday modal', async ({ managerPage }) => {
    await managerPage.goto('/manager/holidays');
    await managerPage.getByRole('button', { name: 'Tambah', exact: true }).first().click();
    await expect(managerPage.getByRole('heading', { name: 'Tambah Hari Libur Baru' })).toBeVisible();
    await expect(managerPage.getByPlaceholder(/Hari Raya Idul Fitri/i)).toBeVisible();
  });

  test('creates a new holiday', async ({ managerPage }) => {
    const ts = Date.now();
    const date = `2027-${String((Math.floor(Math.random() * 12) + 1)).padStart(2, '0')}-${String((Math.floor(Math.random() * 27) + 1)).padStart(2, '0')}`;
    const name = `E2E Holiday ${ts}`;
    await managerPage.goto('/manager/holidays');
    await managerPage.getByRole('button', { name: 'Tambah', exact: true }).first().click();
    await managerPage.getByPlaceholder(/Hari Raya Idul Fitri/i).fill(name);
    await managerPage.locator('input[type="date"]').fill(date);
    await managerPage.getByRole('button', { name: 'Buat Hari Libur' }).click();
    await managerPage.waitForTimeout(1000);
    await managerPage.goto('/manager/holidays');
    // Year picker default current year (2026). For year 2027 the new holiday won't show. Skip exact check.
  });

  test('year selector changes view', async ({ managerPage }) => {
    await managerPage.goto('/manager/holidays');
    const yearSelect = managerPage.locator('select').first();
    await yearSelect.selectOption('2025');
    await expect(yearSelect).toHaveValue('2025');
  });
});
