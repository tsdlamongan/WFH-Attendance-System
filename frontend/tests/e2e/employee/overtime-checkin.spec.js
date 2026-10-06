import { test, expect } from '../fixtures/auth.js';
import { mockTodayOvertimeState } from '../fixtures/overtime-mock.js';

// "Required hours already met" can't be reached with the seeded data, so today's status and the
// check-in call are mocked at the HTTP layer (see fixtures/overtime-mock.js). Nothing is persisted.
test.describe('Employee overtime check-in', () => {
  test('modal has no overtime fields when overtime is not required', async ({ employeeBetaPage }) => {
    const state = await mockTodayOvertimeState(employeeBetaPage, { requiresOvertime: false });
    await employeeBetaPage.goto('/employee/dashboard');
    await employeeBetaPage.getByRole('button', { name: /^Check In$/ }).first().click();
    await expect(employeeBetaPage.getByText('Tambahkan tugas yang akan Anda kerjakan hari ini')).toBeVisible();
    await expect(employeeBetaPage.getByLabel(/Alasan lembur/)).toHaveCount(0);
    await expect(employeeBetaPage.getByLabel(/Durasi lembur/)).toHaveCount(0);

    await employeeBetaPage.getByPlaceholder('Tugas 1').fill('Task biasa');
    await employeeBetaPage.locator('form').getByRole('button', { name: /^Check In$/ }).click();
    await expect.poll(() => state.checkInRequests.length).toBe(1);
    // Regular check-in keeps the original payload: no overtime key
    expect(state.checkInRequests[0]).toEqual({ tasks: [{ title: 'Task biasa' }] });
  });

  test('modal asks for reason and duration when required hours are met', async ({ employeeBetaPage }) => {
    const state = await mockTodayOvertimeState(employeeBetaPage, { requiresOvertime: true });
    await employeeBetaPage.goto('/employee/dashboard');
    await expect(employeeBetaPage.getByText('Jam kerja wajib hari ini sudah terpenuhi').first()).toBeVisible({ timeout: 10_000 });

    await employeeBetaPage.getByRole('button', { name: /^Check In$/ }).first().click();
    await expect(employeeBetaPage.getByRole('heading', { name: 'Check-in lembur' })).toBeVisible();
    await expect(employeeBetaPage.getByLabel(/Durasi lembur/)).toHaveValue('60');

    await employeeBetaPage.getByPlaceholder('Tugas 1').fill('Task lembur');
    await employeeBetaPage.getByLabel(/Alasan lembur/).fill('Abc');
    // Bypass HTML5 minLength so JS validation in handleSubmit runs
    await employeeBetaPage.locator('form').evaluate((form) => { form.noValidate = true; });
    await employeeBetaPage.locator('form').getByRole('button', { name: /^Check In$/ }).click();
    await expect(employeeBetaPage.getByText('Alasan lembur harus minimal 5 karakter')).toBeVisible();
    expect(state.checkInRequests).toHaveLength(0);

    await employeeBetaPage.getByLabel(/Alasan lembur/).fill('Menyelesaikan deployment mendesak');
    await employeeBetaPage.getByLabel(/Durasi lembur/).selectOption('90');
    await employeeBetaPage.locator('form').getByRole('button', { name: /^Check In$/ }).click();
    await expect.poll(() => state.checkInRequests.length).toBe(1);
    expect(state.checkInRequests[0]).toEqual({
      tasks: [{ title: 'Task lembur' }],
      overtime: { reason: 'Menyelesaikan deployment mendesak', planned_minutes: 90 },
    });
  });

  test('shows pending overtime banner without asking again', async ({ employeeBetaPage }) => {
    await mockTodayOvertimeState(employeeBetaPage, {
      requiresOvertime: false,
      overtime: {
        status: 'pending', planned_minutes: 60, approved_minutes: null, allowed_minutes: 60,
        used_minutes: 0, remaining_minutes: 60, reason: 'Lembur deployment', notes: null,
      },
    });
    await employeeBetaPage.goto('/employee/dashboard');
    await expect(employeeBetaPage.getByText('Menunggu persetujuan manager')).toBeVisible({ timeout: 10_000 });

    // A record already exists, so the check-in modal doesn't ask for overtime details again
    await employeeBetaPage.getByRole('button', { name: /^Check In$/ }).first().click();
    await expect(employeeBetaPage.getByLabel(/Alasan lembur/)).toHaveCount(0);
  });

  test('shows approved overtime banner with remaining time', async ({ employeeBetaPage }) => {
    await mockTodayOvertimeState(employeeBetaPage, {
      overtime: {
        status: 'approved', planned_minutes: 120, approved_minutes: 90, allowed_minutes: 90,
        used_minutes: 30, remaining_minutes: 60, reason: 'Lembur deployment', notes: null,
      },
    });
    await employeeBetaPage.goto('/employee/dashboard');
    await expect(employeeBetaPage.getByText('Lembur disetujui')).toBeVisible({ timeout: 10_000 });
    await expect(employeeBetaPage.getByText(/Sisa waktu lembur:\s*1 jam\s*dari 1 jam 30 menit/)).toBeVisible();
  });

  test('shows rejected overtime banner with the manager note and surfaces the backend error on check-in', async ({ employeeBetaPage }) => {
    const message = 'Pengajuan lembur Anda hari ini ditolak. Anda tidak dapat check-in lembur.';
    const state = await mockTodayOvertimeState(employeeBetaPage, {
      overtime: {
        status: 'rejected', planned_minutes: 60, approved_minutes: null, allowed_minutes: 0,
        used_minutes: 0, remaining_minutes: 0, reason: 'Lembur deployment', notes: 'Tidak ada kebutuhan lembur',
      },
      checkInError: message,
    });
    await employeeBetaPage.goto('/employee/dashboard');
    await expect(employeeBetaPage.getByText('Lembur ditolak')).toBeVisible({ timeout: 10_000 });
    await expect(employeeBetaPage.getByText('Tidak ada kebutuhan lembur')).toBeVisible();

    // No extra client-side blocking: the check-in is attempted and the backend message is shown
    await employeeBetaPage.getByRole('button', { name: /^Check In$/ }).first().click();
    await employeeBetaPage.getByPlaceholder('Tugas 1').fill('Task setelah ditolak');
    await employeeBetaPage.locator('form').getByRole('button', { name: /^Check In$/ }).click();
    await expect(employeeBetaPage.getByText(message)).toBeVisible();
    expect(state.checkInRequests).toHaveLength(1);
  });
});
