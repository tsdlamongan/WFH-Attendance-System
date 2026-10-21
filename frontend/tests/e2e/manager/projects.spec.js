import { readFileSync } from 'node:fs';
import { test, expect } from '../fixtures/auth.js';
import { login, callApi } from '../fixtures/api.js';

// Team Beta is used on purpose: most other specs work on Team Alpha, whose check-in form must
// stay free of the project choice.
const MANAGER = 'manager.beta@e2e.test';
const EMPLOYEE = 'employee.beta1@e2e.test';

const stamp = Date.now();
const PROJECT = `E2E Project ${stamp}`;
const DISPOSABLE = `E2E Hapus ${stamp}`;
const TASK = `E2E integrasi pembayaran ${stamp}`;

/** Checks the employee out through the API when a session is still running. */
async function ensureCheckedOut(email) {
  const { token } = await login(email);
  const session = (await callApi(token, 'GET', '/attendance/today')).body?.data?.current_session;
  if (!session) return;

  const res = await callApi(token, 'POST', '/attendance/check-out', {
    attendance_id: session.id,
    tasks: session.tasks.map((task) => ({ id: task.id, is_completed: true })),
  });
  if (!res.ok) {
    throw new Error(`Could not check out ${email}: ${res.status} ${JSON.stringify(res.body)}`);
  }
}

/** Hides every project of the manager's team from the check-in form again. */
async function deactivateProjects(email) {
  const { token } = await login(email);
  const projects = (await callApi(token, 'GET', '/manager/projects')).body?.data ?? [];
  for (const project of projects.filter((item) => item.is_active)) {
    await callApi(token, 'PUT', `/manager/projects/${project.id}`, { name: project.name, is_active: false });
  }
}

const projectRow = (page, name) => page.getByRole('row').filter({ hasText: name });

test.describe.serial('Manager Projects & Super Admin Evaluation Report', () => {
  test.beforeAll(async () => {
    await ensureCheckedOut(EMPLOYEE);
  });

  test.afterAll(async () => {
    // Leave the team as other specs expect it: employee checked out, no project to choose at check-in.
    await ensureCheckedOut(EMPLOYEE);
    await deactivateProjects(MANAGER);
  });

  test('renders the project page from the sidebar', async ({ managerBetaPage: page }) => {
    await page.goto('/manager/dashboard');
    await page.getByRole('link', { name: 'Project', exact: true }).click();

    await expect(page).toHaveURL(/\/manager\/projects$/);
    await expect(page.getByRole('heading', { name: 'Manajemen Project' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Tambah Project', exact: true })).toBeVisible();
    await expect(page.getByText('Project Aktif')).toBeVisible();
  });

  test('creates a project', async ({ managerBetaPage: page }) => {
    await page.goto('/manager/projects');
    await page.getByRole('button', { name: 'Tambah Project', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Tambah Project Baru' })).toBeVisible();

    await page.getByLabel(/Nama Project/).fill(PROJECT);
    await page.getByRole('button', { name: 'Buat Project' }).click();

    await expect(page.getByText('Project berhasil ditambahkan.')).toBeVisible();
    await expect(projectRow(page, PROJECT)).toBeVisible();
    await expect(projectRow(page, PROJECT)).toContainText('0 tugas tercatat');
  });

  test('rejects a duplicate project name', async ({ managerBetaPage: page }) => {
    await page.goto('/manager/projects');
    await page.getByRole('button', { name: 'Tambah Project', exact: true }).click();
    await page.getByLabel(/Nama Project/).fill(PROJECT);
    await page.getByRole('button', { name: 'Buat Project' }).click();

    await expect(page.getByText('Nama project ini sudah terdaftar untuk tim Anda.')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Tambah Project Baru' })).toBeVisible();
  });

  test('changes status and progress straight in the table', async ({ managerBetaPage: page }) => {
    await page.goto('/manager/projects');
    const progress = page.getByLabel(`Progress ${PROJECT}`);
    const status = page.getByLabel(`Status ${PROJECT}`);

    // Marking a project as finished also completes its progress.
    await status.selectOption('completed');
    await expect(progress).toHaveValue('100');

    await status.selectOption('on_progress');
    await expect(status).toHaveValue('on_progress');
    await progress.selectOption('70');
    await expect(progress).toHaveValue('70');
    await expect(progress).toBeEnabled();

    await page.reload();
    await expect(page.getByLabel(`Progress ${PROJECT}`)).toHaveValue('70');
    await expect(page.getByLabel(`Status ${PROJECT}`)).toHaveValue('on_progress');
  });

  test('edits PIC and note in the form', async ({ managerBetaPage: page }) => {
    await page.goto('/manager/projects');
    await page.getByRole('button', { name: `Edit ${PROJECT}` }).click();
    await expect(page.getByRole('heading', { name: 'Edit Project' })).toBeVisible();
    await expect(page.getByLabel(/Nama Project/)).toHaveValue(PROJECT);

    await page.getByLabel('PIC').fill('Tim Beta');
    await page.getByLabel('Catatan').fill('Proses QA');
    await page.getByRole('button', { name: 'Perbarui Project' }).click();

    await expect(page.getByText('Project berhasil diperbarui.')).toBeVisible();
    await expect(projectRow(page, PROJECT)).toContainText('Tim Beta');
    await expect(projectRow(page, PROJECT)).toContainText('Proses QA');
  });

  test('deletes a project that was never used', async ({ managerBetaPage: page }) => {
    const { token } = await login(MANAGER);
    const created = await callApi(token, 'POST', '/manager/projects', { name: DISPOSABLE, is_active: false });
    expect(created.status).toBe(201);

    await page.goto('/manager/projects');
    await expect(projectRow(page, DISPOSABLE)).toContainText('Nonaktif');

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: `Hapus ${DISPOSABLE}` }).click();

    await expect(page.getByText('Project berhasil dihapus.')).toBeVisible();
    await expect(projectRow(page, DISPOSABLE)).toHaveCount(0);
  });

  test('employee has to pick a project at check-in', async ({ employeeBetaPage: page }) => {
    await page.goto('/employee/dashboard');
    await page.getByRole('button', { name: /^Check In$/ }).first().click();

    const projectSelect = page.getByLabel('Project untuk tugas 1');
    await expect(projectSelect).toBeVisible();
    await expect(projectSelect.locator('option')).toContainText(['Pilih project', PROJECT, 'Lainnya (di luar project)']);

    await page.getByPlaceholder('Tugas 1').fill(TASK);
    await page.locator('form').getByRole('button', { name: /^Check In$/ }).click();
    await expect(page.getByText(/Pilih project untuk setiap tugas/)).toBeVisible();
    await expect(page.getByText('Belum Check In')).toBeVisible();

    await projectSelect.selectOption({ label: PROJECT });
    // A row added afterwards starts on the same project.
    await page.getByRole('button', { name: /Tambah Tugas/ }).click();
    await expect(page.getByLabel('Project untuk tugas 2')).toHaveValue(await projectSelect.inputValue());
    await page.getByPlaceholder('Tugas 2').fill('E2E rapat koordinasi');
    await page.getByLabel('Project untuk tugas 2').selectOption({ label: 'Lainnya (di luar project)' });

    await page.locator('form').getByRole('button', { name: /^Check In$/ }).click();

    await expect(page.getByText('Sudah Check In')).toBeVisible({ timeout: 10_000 });
    const session = page.locator('.card').filter({ hasText: 'Sesi Saat Ini' });
    await expect(session.getByText(TASK)).toBeVisible();
    await expect(session.getByText(PROJECT)).toHaveCount(1);
    await expect(session.getByText('E2E rapat koordinasi')).toBeVisible();
  });

  test('project page counts the logged task and refuses deletion', async ({ managerBetaPage: page }) => {
    await page.goto('/manager/projects');

    await expect(projectRow(page, PROJECT)).toContainText('1 tugas tercatat');
    await expect(page.getByRole('button', { name: `Hapus ${PROJECT}` })).toBeDisabled();
  });

  test('evaluation report is a super admin menu that waits for teams and period', async ({ superAdminPage: page }) => {
    const reportRequests = [];
    page.on('request', (request) => {
      if (request.url().includes('/reports/evaluation')) reportRequests.push(request.url());
    });

    await page.goto('/super-admin/teams');
    await page.getByRole('link', { name: 'Laporan Evaluasi', exact: true }).click();

    await expect(page).toHaveURL(/\/super-admin\/evaluation-report$/);
    await expect(page.getByRole('heading', { name: 'Laporan Evaluasi', level: 1 })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Team Alpha E2E', exact: true })).not.toBeChecked();
    await expect(page.getByRole('checkbox', { name: 'Team Beta E2E', exact: true })).not.toBeChecked();

    // Nothing is generated on open and nothing can be generated before a team is chosen.
    await expect(page.getByText('Laporan belum dibuat')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Tampilkan' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Download PDF' })).toBeDisabled();
    expect(reportRequests).toEqual([]);

    await page.getByRole('checkbox', { name: 'Team Beta E2E', exact: true }).check();
    await expect(page.getByRole('button', { name: 'Tampilkan' })).toBeEnabled();
    expect(reportRequests).toEqual([]);
  });

  test('managers no longer have the evaluation report', async ({ managerBetaPage: page }) => {
    await page.goto('/manager/dashboard');
    await expect(page.getByRole('link', { name: 'Project', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Laporan Evaluasi', exact: true })).toHaveCount(0);

    await page.goto('/super-admin/evaluation-report');
    await expect(page).toHaveURL(/\/manager\/dashboard$/);
  });

  test('evaluation report previews staff hours and the work per project of a team', async ({ superAdminPage: page }) => {
    await page.goto('/super-admin/evaluation-report');
    await page.getByRole('checkbox', { name: 'Team Beta E2E', exact: true }).check();
    await page.getByRole('button', { name: 'Tampilkan' }).click();

    await expect(page.getByText('DIVISI TEAM BETA E2E')).toBeVisible();

    const staff = page.getByTestId('evaluation-staff-table');
    await expect(staff.getByRole('row').filter({ hasText: 'Manager Beta' })).toBeVisible();
    await expect(staff.getByRole('row').filter({ hasText: 'Employee Beta One' })).toBeVisible();
    await expect(staff.getByRole('row').filter({ hasText: 'Manager Alpha' })).toHaveCount(0);

    const row = page.getByTestId('evaluation-project-table').getByRole('row').filter({ hasText: PROJECT });
    await expect(row).toContainText('Tim Beta');
    await expect(row).toContainText('70%');
    await expect(row).toContainText(TASK);
    await expect(row).toContainText('Proses QA');
    // The task logged under "Lainnya" belongs to no project.
    await expect(page.getByTestId('evaluation-project-table')).not.toContainText('E2E rapat koordinasi');
  });

  test('evaluation report merges several teams into one report', async ({ superAdminPage: page }) => {
    await page.goto('/super-admin/evaluation-report');
    await page.getByRole('checkbox', { name: 'Team Beta E2E', exact: true }).check();
    await page.getByRole('button', { name: 'Tampilkan' }).click();
    await expect(page.getByText('DIVISI TEAM BETA E2E')).toBeVisible();

    // Changing the selection drops the preview: it would no longer match the chosen teams.
    await page.getByRole('checkbox', { name: 'Team Alpha E2E', exact: true }).check();
    await expect(page.getByText('Laporan belum dibuat')).toBeVisible();
    await expect(page.getByTestId('evaluation-staff-table')).toHaveCount(0);

    await page.getByRole('button', { name: 'Tampilkan' }).click();
    await expect(page.getByText('DIVISI TEAM ALPHA E2E & TEAM BETA E2E')).toBeVisible();

    const staff = page.getByTestId('evaluation-staff-table');
    for (const name of ['Manager Alpha', 'Employee Alpha One', 'Manager Beta', 'Employee Beta One']) {
      await expect(staff.getByRole('row').filter({ hasText: name })).toBeVisible();
    }
    await expect(page.getByTestId('evaluation-project-table').getByRole('row').filter({ hasText: PROJECT })).toBeVisible();
  });

  test('evaluation report can select and clear every team at once', async ({ superAdminPage: page }) => {
    await page.goto('/super-admin/evaluation-report');

    await page.getByRole('button', { name: 'Pilih semua tim' }).click();
    await expect(page.getByRole('checkbox', { name: 'Team Alpha E2E', exact: true })).toBeChecked();
    await expect(page.getByRole('checkbox', { name: 'Team Beta E2E', exact: true })).toBeChecked();

    await page.getByRole('button', { name: 'Kosongkan pilihan' }).click();
    await expect(page.getByRole('checkbox', { name: 'Team Alpha E2E', exact: true })).not.toBeChecked();
    await expect(page.getByRole('button', { name: 'Download PDF' })).toBeDisabled();
  });

  test('evaluation report rejects a reversed period', async ({ superAdminPage: page }) => {
    await page.goto('/super-admin/evaluation-report');
    await page.getByRole('checkbox', { name: 'Team Beta E2E', exact: true }).check();
    await page.getByLabel('Tanggal Mulai').fill('2026-10-10');
    await page.getByLabel('Tanggal Akhir').fill('2026-10-01');
    await page.getByRole('button', { name: 'Download PDF' }).click();

    await expect(page.getByText('Tanggal akhir tidak boleh sebelum tanggal mulai')).toBeVisible();
  });

  test('downloads the evaluation report as a PDF', async ({ superAdminPage: page }) => {
    await page.goto('/super-admin/evaluation-report');
    await page.getByRole('checkbox', { name: 'Team Beta E2E', exact: true }).check();

    // Rendering the PDF (plus the optional Gemini summary) may take longer than a click.
    const downloadPromise = page.waitForEvent('download', { timeout: 30_000 });
    await page.getByRole('button', { name: 'Download PDF' }).click();
    const download = await downloadPromise;

    // The name comes from the backend (team slug + period), so it also proves the header is readable.
    expect(download.suggestedFilename()).toMatch(/^laporan-evaluasi-team-beta-e2e-\d{4}-\d{2}-\d{2}-sd-\d{4}-\d{2}-\d{2}\.pdf$/);
    const file = readFileSync(await download.path());
    expect(file.subarray(0, 5).toString()).toBe('%PDF-');
    expect(file.length).toBeGreaterThan(50_000);

    await expect(page.getByText('Laporan evaluasi berhasil diunduh')).toBeVisible();
  });
});
