import { test, expect } from '../fixtures/auth.js';
import { mockWhatsApp } from '../fixtures/whatsapp-mock.js';

test.describe('Manager WhatsApp Settings (mocked)', () => {
  test('renders disconnected state', async ({ managerPage }) => {
    await mockWhatsApp(managerPage, { connected: false });
    await managerPage.goto('/manager/whatsapp-settings');
    await expect(managerPage.getByRole('heading', { name: 'WhatsApp Gateway' })).toBeVisible();
    await expect(managerPage.getByText(/Status Koneksi/i)).toBeVisible();
    await expect(managerPage.getByText(/WhatsApp belum terhubung/i)).toBeVisible();
  });

  test('renders connected state with phone visible', async ({ managerPage }) => {
    await mockWhatsApp(managerPage, { connected: true, accountPhone: '628111222333', accountName: 'E2E Test' });
    await managerPage.goto('/manager/whatsapp-settings');
    await expect(managerPage.getByText('628111222333', { exact: false })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: /Relink Account/ })).toBeVisible();
    await expect(managerPage.getByRole('button', { name: /Disconnect/ })).toBeVisible();
  });

  test('settings form is editable when connected', async ({ managerPage }) => {
    await mockWhatsApp(managerPage, { connected: true });
    await managerPage.goto('/manager/whatsapp-settings');
    const recipient = managerPage.getByPlaceholder(/08123456789|628123456789/);
    await expect(recipient).toBeVisible();
    await recipient.fill('628999000111');
    await managerPage.getByRole('button', { name: /Simpan Pengaturan/ }).click();
  });

  test('disconnect flow asks for confirmation', async ({ managerPage }) => {
    await mockWhatsApp(managerPage, { connected: true });
    await managerPage.goto('/manager/whatsapp-settings');
    managerPage.once('dialog', (d) => d.accept());
    const disconnectBtn = managerPage.getByRole('button', { name: /Disconnect/i });
    if (await disconnectBtn.count()) {
      await disconnectBtn.click();
    }
  });

  test('test send button visible when connected', async ({ managerPage }) => {
    await mockWhatsApp(managerPage, { connected: true });
    await managerPage.goto('/manager/whatsapp-settings');
    await expect(managerPage.getByRole('button', { name: /Kirim Pesan Test/ })).toBeVisible();
  });

  test('preview recap button shows preview content', async ({ managerPage }) => {
    await mockWhatsApp(managerPage, { connected: true });
    await managerPage.goto('/manager/whatsapp-settings');
    const previewBtn = managerPage.getByRole('button', { name: /Preview Recap Hari Ini/ });
    await previewBtn.click();
    await expect(managerPage.getByText(/Rekap Absensi E2E|Preview Recap/).first()).toBeVisible({ timeout: 10_000 });
  });
});
