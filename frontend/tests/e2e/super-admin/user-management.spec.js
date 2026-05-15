import { test, expect } from '../fixtures/auth.js';

test.describe('Super Admin User Management', () => {
  test('renders user management page', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/users');
    await expect(superAdminPage.getByRole('heading', { name: 'Manajemen Pengguna' })).toBeVisible();
    await expect(superAdminPage.getByRole('button', { name: /Tambah Pengguna/ })).toBeVisible();
  });

  test('shows users across teams', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/users');
    await expect(superAdminPage.getByText('manager.alpha@e2e.test')).toBeVisible();
    await expect(superAdminPage.getByText('manager.beta@e2e.test')).toBeVisible();
    await expect(superAdminPage.getByText('superadmin@e2e.test')).toBeVisible();
  });

  test('opens create user modal with team selector', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/users');
    await superAdminPage.getByRole('button', { name: /Tambah Pengguna/ }).click();
    await expect(superAdminPage.getByRole('heading', { name: 'Tambah Pengguna Baru' })).toBeVisible();
    // Team select with default "Pilih Tim"
    await expect(superAdminPage.locator('select').filter({ hasText: /Pilih Tim/ }).first()).toBeVisible();
    await superAdminPage.getByRole('button', { name: 'Batal' }).click();
  });

  test('impersonate icon present for non-super-admin', async ({ superAdminPage }) => {
    await superAdminPage.goto('/super-admin/users');
    const row = superAdminPage.getByRole('row').filter({ hasText: 'employee.alpha1@e2e.test' });
    await expect(row.getByTitle('Impersonate')).toBeVisible();
  });
});
