import { test, expect } from '../fixtures/auth.js';

test.describe('Manager Team Settings', () => {
  test('renders team settings page', async ({ managerPage }) => {
    await managerPage.goto('/manager/team-settings');
    await expect(managerPage.getByRole('heading', { name: 'Pengaturan Tim' })).toBeVisible();
    await expect(managerPage.getByRole('heading', { name: 'Informasi Tim' })).toBeVisible();
    await expect(managerPage.getByRole('heading', { name: 'Pengaturan Jam Kerja' })).toBeVisible();
    await expect(managerPage.getByRole('heading', { name: 'Pengaturan Cuti' })).toBeVisible();
  });

  test('shows team name as Team Alpha E2E', async ({ managerPage }) => {
    await managerPage.goto('/manager/team-settings');
    const teamNameInput = managerPage.getByPlaceholder('Nama tim Anda');
    await expect(teamNameInput).toHaveValue('Team Alpha E2E');
  });

  test('save button present', async ({ managerPage }) => {
    await managerPage.goto('/manager/team-settings');
    await expect(managerPage.getByRole('button', { name: /Simpan Perubahan/ })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: /Reset/ })).toBeVisible();
  });
});
