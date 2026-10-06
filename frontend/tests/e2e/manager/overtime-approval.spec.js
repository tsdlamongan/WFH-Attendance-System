import { test, expect } from '../fixtures/auth.js';
import { ensurePendingOvertime, listOvertimesAs, resolvePendingOvertimes } from '../fixtures/api.js';

const MANAGER = 'manager.alpha@e2e.test';
const ALPHA1 = 'employee.alpha1@e2e.test';
const ALPHA2 = 'employee.alpha2@e2e.test';

const overtimeCard = (page, text) => page.getByRole('article').filter({ hasText: text });
const filterSelect = (page) => page.getByLabel('Filter status');

// Serial: each test consumes an employee's overtime slot for today (one non-rejected record per
// employee per day, approved ones can't be undone), so the order below is deliberate:
// alpha1 ends up approved (approve test), alpha2 ends up rejected then re-assigned by the manager.
test.describe.serial('Manager Overtime Approval', () => {
  test('renders page with pending filter by default', async ({ managerPage }) => {
    await managerPage.goto('/manager/overtime');
    await expect(managerPage.getByRole('heading', { name: 'Persetujuan Lembur' })).toBeVisible();
    await expect(filterSelect(managerPage)).toHaveValue('pending');
    await expect(managerPage.getByRole('button', { name: /Tugaskan Lembur/ })).toBeVisible();
    await expect(managerPage.getByRole('link', { name: /Persetujuan Lembur/ })).toBeVisible();
  });

  test('sidebar badge shows the pending overtime count', async ({ managerPage }) => {
    await resolvePendingOvertimes(MANAGER);
    const badge = managerPage.getByTestId('pending-overtime-badge');

    await Promise.all([
      managerPage.waitForResponse((res) => res.url().includes('/manager/overtimes/pending-count')),
      managerPage.goto('/manager/dashboard'),
    ]);
    await expect(managerPage.getByRole('link', { name: /Persetujuan Lembur/ })).toBeVisible();
    await expect(badge).toHaveCount(0);

    await ensurePendingOvertime(ALPHA1);
    await Promise.all([
      managerPage.waitForResponse((res) => res.url().includes('/manager/overtimes/pending-count')),
      managerPage.reload(),
    ]);
    await expect(badge).toHaveText('1');
  });

  test('lists a pending request with employee, duration, usage, reason and source', async ({ managerPage }) => {
    const pending = await ensurePendingOvertime(ALPHA1);
    await managerPage.goto('/manager/overtime');
    const card = overtimeCard(managerPage, pending.reason);
    await expect(card).toBeVisible();
    await expect(card.getByText('Employee Alpha One')).toBeVisible();
    await expect(card.getByText('Durasi Diminta')).toBeVisible();
    await expect(card.getByText('1 jam', { exact: true })).toBeVisible();
    await expect(card.getByText('Jam Terpakai')).toBeVisible();
    await expect(card.getByText('Menunggu', { exact: true })).toBeVisible();
    // Requested by the employee, so no "Ditugaskan manager" badge
    await expect(card.getByText('Ditugaskan manager')).toHaveCount(0);
    await expect(card.getByRole('button', { name: 'Setujui' })).toBeVisible();
    await expect(card.getByRole('button', { name: 'Tolak' })).toBeVisible();
  });

  test('approves with adjusted minutes', async ({ managerPage }) => {
    const pending = await ensurePendingOvertime(ALPHA1);
    const badge = managerPage.getByTestId('pending-overtime-badge');
    await managerPage.goto('/manager/overtime');
    await expect(badge).toHaveText('1');

    await overtimeCard(managerPage, pending.reason).getByRole('button', { name: 'Setujui' }).click();
    await expect(managerPage.getByRole('heading', { name: 'Setujui Pengajuan Lembur' })).toBeVisible();

    // Defaults to the requested duration; the manager shortens it
    const minutes = managerPage.getByLabel(/Durasi disetujui/);
    await expect(minutes).toHaveValue('60');
    await minutes.fill('30');
    await managerPage.getByLabel(/Catatan/).fill('Cukup 30 menit saja');
    await managerPage.locator('form').getByRole('button', { name: 'Setujui' }).click();

    await expect(managerPage.getByText('Pengajuan lembur disetujui')).toBeVisible();
    // Leaves the pending list and the sidebar badge refreshes right away
    await expect(overtimeCard(managerPage, pending.reason)).toHaveCount(0);
    await expect(badge).toHaveCount(0);

    await filterSelect(managerPage).selectOption('approved');
    const card = overtimeCard(managerPage, pending.reason);
    await expect(card).toBeVisible();
    await expect(card.getByText('Disetujui', { exact: true })).toBeVisible();
    await expect(card.getByText('30 menit', { exact: true })).toBeVisible();
    await expect(card.getByText('Cukup 30 menit saja')).toBeVisible();

    // Contract check: the override reached the backend
    const approved = (await listOvertimesAs(MANAGER, 'approved')).find((o) => o.id === pending.id);
    expect(approved.approved_minutes).toBe(30);
    expect(approved.allowed_minutes).toBe(30);
  });

  test('reject requires a catatan', async ({ managerPage }) => {
    const pending = await ensurePendingOvertime(ALPHA2, { reason: 'E2E lembur yang akan ditolak manager' });
    await managerPage.goto('/manager/overtime');

    await overtimeCard(managerPage, pending.reason).getByRole('button', { name: 'Tolak' }).click();
    const heading = managerPage.getByRole('heading', { name: 'Tolak Pengajuan Lembur' });
    await expect(heading).toBeVisible();

    // Empty catatan: client-side validation, modal stays open and nothing is rejected
    await managerPage.locator('form').getByRole('button', { name: 'Tolak' }).click();
    await expect(managerPage.getByText('Catatan penolakan wajib diisi')).toBeVisible();
    await expect(heading).toBeVisible();
    const stillPending = await listOvertimesAs(MANAGER, 'pending');
    expect(stillPending.some((o) => o.id === pending.id)).toBe(true);

    // Whitespace only counts as empty
    await managerPage.getByLabel(/Catatan Penolakan/).fill('   ');
    await managerPage.locator('form').getByRole('button', { name: 'Tolak' }).click();
    await expect(managerPage.getByText('Catatan penolakan wajib diisi')).toBeVisible();

    await managerPage.getByLabel(/Catatan Penolakan/).fill('Tidak ada kebutuhan lembur hari ini');
    await expect(managerPage.getByText('Catatan penolakan wajib diisi')).toHaveCount(0);
    await managerPage.locator('form').getByRole('button', { name: 'Tolak' }).click();
    await expect(managerPage.getByText('Pengajuan lembur ditolak')).toBeVisible();

    await filterSelect(managerPage).selectOption('rejected');
    const card = overtimeCard(managerPage, pending.reason);
    await expect(card).toBeVisible();
    await expect(card.getByText('Ditolak', { exact: true })).toBeVisible();
    await expect(card.getByText('Tidak ada kebutuhan lembur hari ini')).toBeVisible();
  });

  test('assign modal only lists active employees and validates the form', async ({ managerPage }) => {
    await managerPage.goto('/manager/overtime');
    await managerPage.getByRole('button', { name: /Tugaskan Lembur/ }).click();
    await expect(managerPage.getByRole('heading', { name: 'Tugaskan Lembur' })).toBeVisible();

    const employee = managerPage.getByLabel(/Karyawan/);
    await expect(employee).toBeEnabled();
    await expect(employee.locator('option', { hasText: 'Employee Alpha One' })).toHaveCount(1);
    await expect(employee.locator('option', { hasText: 'Employee Alpha Two' })).toHaveCount(1);
    // Managers, disabled users and other teams' employees are not selectable
    await expect(employee.locator('option', { hasText: 'Manager Alpha' })).toHaveCount(0);
    await expect(employee.locator('option', { hasText: 'Disabled User' })).toHaveCount(0);
    await expect(employee.locator('option', { hasText: 'Employee Beta One' })).toHaveCount(0);

    // Reason filled but no employee picked
    await managerPage.getByLabel(/Alasan lembur/).fill('Penugasan tanpa karyawan');
    await managerPage.locator('form').getByRole('button', { name: 'Tugaskan' }).click();
    await expect(managerPage.getByText('Karyawan harus dipilih')).toBeVisible();
    await managerPage.getByRole('button', { name: 'Batal' }).click();
    await expect(managerPage.getByRole('heading', { name: 'Tugaskan Lembur' })).toHaveCount(0);
  });

  test('assigns overtime to an employee', async ({ managerPage }) => {
    // The employee must not have an undecided request (backend rule), so start clean.
    await resolvePendingOvertimes(MANAGER);
    const reason = 'E2E penugasan lembur migrasi data';

    await managerPage.goto('/manager/overtime');
    await managerPage.getByRole('button', { name: /Tugaskan Lembur/ }).click();
    await managerPage.getByLabel(/Karyawan/).selectOption({ label: 'Employee Alpha Two (employee.alpha2@e2e.test)' });
    await managerPage.getByLabel(/Durasi lembur/).selectOption('120');
    await managerPage.getByLabel(/Alasan lembur/).fill(reason);
    await managerPage.locator('form').getByRole('button', { name: 'Tugaskan' }).click();

    await expect(managerPage.getByText('Lembur berhasil ditugaskan')).toBeVisible();

    // Assigned overtime is auto-approved and marked as coming from the manager
    await filterSelect(managerPage).selectOption('approved');
    const card = overtimeCard(managerPage, reason);
    await expect(card).toBeVisible();
    await expect(card.getByText('Employee Alpha Two')).toBeVisible();
    await expect(card.getByText('Ditugaskan manager')).toBeVisible();
    await expect(card.getByText('Disetujui', { exact: true })).toBeVisible();
    await expect(card.getByText('2 jam', { exact: true })).toHaveCount(2); // diminta + disetujui
  });

  test('empty state and team isolation for another team manager', async ({ managerBetaPage }) => {
    await managerBetaPage.goto('/manager/overtime');
    await expect(managerBetaPage.getByText('Tidak ada pengajuan lembur menunggu ditemukan')).toBeVisible();

    await filterSelect(managerBetaPage).selectOption('');
    await expect(managerBetaPage.getByText('Tidak ada pengajuan lembur ditemukan')).toBeVisible();
    await expect(managerBetaPage.getByText('Employee Alpha One')).toHaveCount(0);
    await expect(managerBetaPage.getByText('Employee Alpha Two')).toHaveCount(0);
    await expect(managerBetaPage.getByTestId('pending-overtime-badge')).toHaveCount(0);
  });
});
