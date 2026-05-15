// Helper to mock WhatsApp endpoints at frontend HTTP layer
// so backend WhatsAppGatewayService never hits external gateway.

export async function mockWhatsApp(page, opts = {}) {
  const {
    connected = false,
    accountPhone = '628111222333',
    accountName = 'E2E WhatsApp',
    accountUniqueId = 'unique-id-e2e-1234567890abcdef',
    recapEnabled = false,
    recipientPhone = '628987654321',
    recapTime = '17:00:00',
  } = opts;

  const state = {
    connected,
    accountPhone,
    accountName,
    accountUniqueId,
    recapEnabled,
    recipientPhone,
    recapTime,
    lastSentAt: null,
  };

  await page.route('**/api/v1/whatsapp/check-connection', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          connected: state.connected,
          account_phone: state.connected ? state.accountPhone : null,
          account_name: state.connected ? state.accountName : null,
          account_unique_id: state.connected ? state.accountUniqueId : null,
          connected_at: state.connected ? new Date().toISOString() : null,
        },
      }),
    });
  });

  const buildSettings = () => ({
    connected: state.connected,
    connected_at: state.connected ? new Date().toISOString() : null,
    account_unique_id: state.connected ? state.accountUniqueId : null,
    account_phone: state.connected ? state.accountPhone : null,
    account_name: state.connected ? state.accountName : null,
    recipient_phone: state.recipientPhone,
    recap_time: state.recapTime.slice(0, 5),
    recap_enabled: state.recapEnabled,
    last_sent_at: state.lastSentAt,
    last_error: null,
    can_send_recap: state.connected && !!state.recipientPhone,
  });

  await page.route('**/api/v1/whatsapp/settings', async (route) => {
    const method = route.request().method();
    if (method === 'GET') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: buildSettings() }),
      });
    } else if (method === 'PUT') {
      const body = route.request().postDataJSON() || {};
      state.recipientPhone = body.whatsapp_recipient_phone ?? body.recipient_phone ?? state.recipientPhone;
      state.recapTime = body.whatsapp_recap_time ?? body.recap_time ?? state.recapTime;
      state.recapEnabled = body.whatsapp_recap_enabled ?? body.recap_enabled ?? state.recapEnabled;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, message: 'Pengaturan disimpan', data: buildSettings() }),
      });
    } else {
      await route.fallback();
    }
  });

  await page.route('**/api/v1/whatsapp/create-link', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { token: 'e2e-link-token', qr_link: 'https://e2e.test/qr.png' },
      }),
    });
  });

  await page.route('**/api/v1/whatsapp/account-info**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { connected: false, account: null },
      }),
    });
  });

  await page.route('**/api/v1/whatsapp/link-existing', async (route) => {
    state.connected = true;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: 'Account terhubung',
        data: { phone: state.accountPhone, name: state.accountName },
      }),
    });
  });

  await page.route('**/api/v1/whatsapp/relink', async (route) => {
    state.connected = true;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { phone: state.accountPhone, name: state.accountName },
      }),
    });
  });

  await page.route('**/api/v1/whatsapp/disconnect', async (route) => {
    state.connected = false;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, message: 'Account disconnected' }),
    });
  });

  await page.route('**/api/v1/whatsapp/test-send', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, message: 'Test message sent' }),
    });
  });

  await page.route('**/api/v1/whatsapp/preview-recap**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          message: '*Rekap Absensi E2E*\nTotal: 0 karyawan',
          data: { checked_in: 0, checked_out: 0, still_checked_in: [], not_checked_in: [] },
        },
      }),
    });
  });

  await page.route('**/api/v1/whatsapp/send-recap', async (route) => {
    state.lastSentAt = new Date().toISOString();
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, message: 'Recap sent' }),
    });
  });

  await page.route('**/api/v1/whatsapp/confirm-link', async (route) => {
    state.connected = true;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { phone: state.accountPhone, name: state.accountName },
      }),
    });
  });

  return state;
}
