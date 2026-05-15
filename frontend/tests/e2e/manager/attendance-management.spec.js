import { test, expect } from '../fixtures/auth.js';

test.describe('Manager Attendance Management', () => {
  test('renders attendance management page', async ({ managerPage }) => {
    await managerPage.goto('/manager/attendances');
    await expect(managerPage.getByRole('heading', { name: 'Manajemen Absensi' })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: /Tambah Absensi/ })).toBeVisible();
  });

  test('has filter section', async ({ managerPage }) => {
    await managerPage.goto('/manager/attendances');
    await expect(managerPage.getByPlaceholder(/Ketik nama karyawan/i).first()).toBeVisible();
    await expect(managerPage.getByRole('button', { name: 'Terapkan Filter' })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: /Hapus Filter/ })).toBeVisible();
  });

  test('opens add attendance modal', async ({ managerPage }) => {
    await managerPage.goto('/manager/attendances');
    await managerPage.getByRole('button', { name: /Tambah Absensi/ }).click();
    await expect(managerPage.getByRole('heading', { name: 'Tambah Absensi' })).toBeVisible();
    await managerPage.getByRole('button', { name: 'Batal' }).click();
  });

  test('add attendance form has all required fields', async ({ managerPage }) => {
    await managerPage.goto('/manager/attendances');
    await managerPage.getByRole('button', { name: /Tambah Absensi/ }).click();
    // Modal has karyawan, tanggal, check-in, alasan, tugas
    await expect(managerPage.locator('input[type="date"]').first()).toBeVisible();
    await expect(managerPage.locator('input[type="datetime-local"]').first()).toBeVisible();
    await expect(managerPage.getByPlaceholder(/Alasan menambah absensi/i)).toBeVisible();
    await managerPage.getByRole('button', { name: 'Batal' }).click();
  });
});
