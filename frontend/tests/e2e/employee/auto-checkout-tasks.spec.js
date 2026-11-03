import { test, expect } from '../fixtures/auth.js';
import { mockAutoCheckoutTasks, ymd } from '../fixtures/auto-checkout-mock.js';

// Auto-checked-out sessions can't be produced with the seeded data, so today's status and the save
// call are mocked at the HTTP layer (see fixtures/auto-checkout-mock.js). Nothing is persisted.
const TASKS = [
  { id: 101, title: 'Integrasi API pembayaran', project: 'SIAKAD', date: ymd(-1) },
  { id: 102, title: 'Laporan nilai semester', date: ymd(-1) },
  { id: 103, title: 'Slicing dashboard', project: 'Sukasir', date: ymd(0) },
];

test.describe('Employee tasks left open by an auto checkout', () => {
  test('lists the open tasks grouped by day', async ({ employeeBetaPage: page }) => {
    await mockAutoCheckoutTasks(page, { tasks: TASKS });
    await page.goto('/employee/dashboard');

    const card = page.getByTestId('auto-checkout-tasks');
    await expect(page.getByRole('heading', { name: 'Tugas Terkena Auto Checkout' })).toBeVisible();
    await expect(card.getByText(/^Kemarin/)).toBeVisible();
    await expect(card.getByText(/^Hari ini/)).toBeVisible();
    for (const task of TASKS) {
      await expect(card.getByRole('checkbox', { name: new RegExp(task.title) })).not.toBeChecked();
    }
    await expect(card.getByText('SIAKAD')).toBeVisible();
    await expect(card.getByRole('button', { name: 'Tandai Selesai' })).toBeDisabled();
  });

  test('ticks off the finished tasks and keeps the rest', async ({ employeeBetaPage: page }) => {
    const state = await mockAutoCheckoutTasks(page, { tasks: TASKS });
    await page.goto('/employee/dashboard');

    const card = page.getByTestId('auto-checkout-tasks');
    await card.getByRole('checkbox', { name: /Integrasi API pembayaran/ }).check();
    await card.getByRole('checkbox', { name: /Slicing dashboard/ }).check();
    await expect(card.getByRole('button', { name: 'Tandai Selesai (2)' })).toBeEnabled();

    await card.getByRole('button', { name: 'Tandai Selesai (2)' }).click();

    await expect(page.getByText('2 tugas ditandai selesai')).toBeVisible();
    expect(state.completeRequests).toEqual([{ task_ids: [101, 103] }]);

    // Saved tasks are gone from the next status, the untouched one stays.
    await expect(card.getByRole('checkbox', { name: /Integrasi API pembayaran/ })).toHaveCount(0);
    await expect(card.getByRole('checkbox', { name: /Slicing dashboard/ })).toHaveCount(0);
    await expect(card.getByRole('checkbox', { name: /Laporan nilai semester/ })).not.toBeChecked();
    await expect(card.getByRole('button', { name: 'Tandai Selesai' })).toBeDisabled();
  });

  test('the card disappears once every task is saved', async ({ employeeBetaPage: page }) => {
    await mockAutoCheckoutTasks(page, { tasks: [TASKS[1]] });
    await page.goto('/employee/dashboard');

    await page.getByRole('checkbox', { name: /Laporan nilai semester/ }).check();
    await page.getByRole('button', { name: 'Tandai Selesai (1)' }).click();

    await expect(page.getByText('1 tugas ditandai selesai')).toBeVisible();
    await expect(page.getByTestId('auto-checkout-tasks')).toHaveCount(0);
  });

  test('shows the reason when the save is refused and keeps the selection', async ({ employeeBetaPage: page }) => {
    const message = 'Hanya tugas yang belum selesai dari sesi yang terkena auto checkout (hari ini atau kemarin) yang dapat diselesaikan.';
    await mockAutoCheckoutTasks(page, { tasks: TASKS, completeError: message });
    await page.goto('/employee/dashboard');

    const card = page.getByTestId('auto-checkout-tasks');
    await card.getByRole('checkbox', { name: /Laporan nilai semester/ }).check();
    await card.getByRole('button', { name: 'Tandai Selesai (1)' }).click();

    await expect(page.getByText(message)).toBeVisible();
    await expect(card.getByRole('checkbox', { name: /Laporan nilai semester/ })).toBeChecked();
  });

  test('has no card when the auto checkout left nothing open', async ({ employeeBetaPage: page }) => {
    await mockAutoCheckoutTasks(page, { tasks: [] });
    await page.goto('/employee/dashboard');

    await expect(page.getByText('Progress Hari Ini')).toBeVisible();
    await expect(page.getByTestId('auto-checkout-tasks')).toHaveCount(0);
  });
});
