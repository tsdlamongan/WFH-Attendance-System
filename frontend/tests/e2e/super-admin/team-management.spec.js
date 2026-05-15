import { test, expect } from '../fixtures/auth.js';

test.describe.serial('Super Admin Team Management', () => {
  test('renders team management page', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/teams');
    await expect(superAdminPage.getByRole('heading', { name: 'Manajemen Tim' })).toBeVisible();
    await expect(superAdminPage.getByRole('button', { name: /Tambah Tim/ }).first()).toBeVisible();
  });

  test('shows seeded teams', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/teams');
    await expect(superAdminPage.getByText('Team Alpha E2E')).toBeVisible();
    await expect(superAdminPage.getByText('Team Beta E2E')).toBeVisible();
  });

  test('opens create team modal', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/teams');
    await superAdminPage.getByRole('button', { name: /Tambah Tim/ }).first().click();
    await expect(superAdminPage.getByRole('heading', { name: 'Tambah Tim Baru' })).toBeVisible();
    await expect(superAdminPage.getByPlaceholder('Contoh: Tim Development')).toBeVisible();
    await superAdminPage.getByRole('button', { name: 'Batal' }).click();
  });

  test('creates a new team', async ({ superAdminPage }) => {
    const ts = Date.now();
    const name = `E2E Team ${ts}`;
    await superAdminPage.goto('/super-admin/teams');
    await superAdminPage.getByRole('button', { name: /Tambah Tim/ }).first().click();
    await superAdminPage.getByPlaceholder('Contoh: Tim Development').fill(name);
    // Number inputs for jam, kuota, max cuti
    await superAdminPage.locator('input[type="number"]').nth(0).fill('7');
    await superAdminPage.locator('input[type="number"]').nth(1).fill('12');
    await superAdminPage.locator('input[type="number"]').nth(2).fill('5');
    await superAdminPage.getByRole('button', { name: 'Tambah Tim', exact: true }).last().click();
    await expect(superAdminPage.getByText(name)).toBeVisible({ timeout: 10_000 });
  });
});
