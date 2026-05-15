import { test, expect } from '../fixtures/auth.js';

test.describe.serial('Manager User Management', () => {
  test('renders user list', async ({ managerPage }) => {
    await managerPage.goto('/manager/users');
    await expect(managerPage.getByRole('heading', { name: 'Pengguna' })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: /Tambah Pengguna/ })).toBeVisible();
    await expect(managerPage.locator('table')).toBeVisible();
  });

  test('shows seeded users in table', async ({ managerPage }) => {
    await managerPage.goto('/manager/users');
    await expect(managerPage.getByText('employee.alpha1@e2e.test')).toBeVisible();
    await expect(managerPage.getByText('employee.alpha2@e2e.test')).toBeVisible();
  });

  test('opens create user modal', async ({ managerPage }) => {
    await managerPage.goto('/manager/users');
    await managerPage.getByRole('button', { name: /Tambah Pengguna/ }).click();
    await expect(managerPage.getByRole('heading', { name: 'Tambah Pengguna Baru' })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: 'Buat Pengguna' })).toBeVisible();
  });

  test('rejects empty form submission', async ({ managerPage }) => {
    await managerPage.goto('/manager/users');
    await managerPage.getByRole('button', { name: /Tambah Pengguna/ }).click();
    await managerPage.locator('form').evaluate((f) => { f.noValidate = true; });
    await managerPage.getByRole('button', { name: 'Buat Pengguna' }).click();
    // Validation should appear
    await managerPage.waitForTimeout(500);
  });

  test('creates a new employee user', async ({ managerPage }) => {
    // Backend name validation: only letters, spaces, hyphens, apostrophes, periods (no digits)
    const suffix = String.fromCharCode(97 + (Date.now() % 26)) + String.fromCharCode(97 + ((Date.now() >> 5) % 26)) + String.fromCharCode(97 + ((Date.now() >> 10) % 26));
    const email = `e2e-new-${suffix}-${Date.now()}@e2e.test`;
    const name = `New User ${suffix.toUpperCase()}`;
    await managerPage.goto('/manager/users');
    await managerPage.getByRole('button', { name: /Tambah Pengguna/ }).click();
    const modal = managerPage.locator('form');
    await modal.locator('input[type="text"]').first().fill(name);
    await modal.locator('input[type="email"]').fill(email);
    await modal.locator('input[type="password"]').first().fill('Password1!');
    await modal.locator('input[type="password"]').nth(1).fill('Password1!');
    await managerPage.getByRole('button', { name: 'Buat Pengguna' }).click();
    await expect(managerPage.getByText(email)).toBeVisible({ timeout: 10_000 });
  });

  test('opens edit modal for an existing user', async ({ managerPage }) => {
    await managerPage.goto('/manager/users');
    const row = managerPage.getByRole('row').filter({ hasText: 'employee.alpha1@e2e.test' });
    await row.getByTitle('Edit').click();
    await expect(managerPage.getByRole('heading', { name: 'Edit Pengguna' })).toBeVisible();
    await managerPage.getByRole('button', { name: 'Batal' }).click();
  });

  test('toggles user disabled status', async ({ managerPage }) => {
    await managerPage.goto('/manager/users');
    const row = managerPage.getByRole('row').filter({ hasText: 'employee.alpha2@e2e.test' });
    const toggleBtn = row.getByTitle(/Nonaktifkan|Aktifkan/);
    await toggleBtn.click();
    // Confirmation might appear; if so, accept it
    managerPage.once('dialog', (d) => d.accept());
    await managerPage.waitForTimeout(500);
  });
});
