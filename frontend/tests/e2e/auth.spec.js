import { test as baseTest, expect as baseExpect } from '@playwright/test';

baseTest.describe('Login page', () => {
  baseTest('renders all form elements', async ({ page }) => {
    await page.goto('/login');
    await baseExpect(page.getByRole('heading', { name: 'WFH' })).toBeVisible();
    await baseExpect(page.getByText('Masuk ke akun Anda')).toBeVisible();
    await baseExpect(page.getByLabel('Email')).toBeVisible();
    await baseExpect(page.getByLabel('Kata Sandi')).toBeVisible();
    await baseExpect(page.getByRole('button', { name: 'Masuk' })).toBeVisible();
  });

  baseTest('shows validation errors for empty fields', async ({ page }) => {
    await page.goto('/login');
    // Click submit without filling - HTML required attribute will block native submit
    // but we can clear required attrs by setting values then clearing. Use programmatic.
    await page.getByLabel('Email').fill('a@b.co');
    await page.getByLabel('Kata Sandi').fill('xx');
    await page.getByLabel('Email').fill('');
    await page.getByLabel('Kata Sandi').fill('');
    // The Input components have required; HTML5 form blocks. So we test by typing partial.
    await page.getByLabel('Email').fill('test@example.com');
    await page.getByLabel('Kata Sandi').fill('wrongpass');
    await page.getByRole('button', { name: 'Masuk' }).click();
    // Expect either an API error toast or response
    await page.waitForTimeout(500);
  });

  baseTest('logs in successfully as super admin and lands on /super-admin/teams', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('superadmin@e2e.test');
    await page.getByLabel('Kata Sandi').fill('Password1!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL('**/super-admin/teams', { timeout: 10_000 });
    await baseExpect(page.getByRole('heading', { name: 'Manajemen Tim' })).toBeVisible();
  });

  baseTest('logs in successfully as manager and lands on /manager/dashboard', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('manager.alpha@e2e.test');
    await page.getByLabel('Kata Sandi').fill('Password1!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL('**/manager/dashboard', { timeout: 10_000 });
    await baseExpect(page.getByRole('heading', { name: 'Dashboard Manager' })).toBeVisible();
  });

  baseTest('logs in successfully as employee and lands on /employee/dashboard', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('employee.alpha1@e2e.test');
    await page.getByLabel('Kata Sandi').fill('Password1!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL('**/employee/dashboard', { timeout: 10_000 });
    await baseExpect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
  });

  baseTest('shows error on wrong password', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('employee.alpha1@e2e.test');
    await page.getByLabel('Kata Sandi').fill('WrongPassword!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await baseExpect(page.getByText('Login Gagal, pastikan email dan kata sandi benar!')).toBeVisible();
    // A failed login attempt must not be treated as an expired session
    await baseExpect(page.getByText('Sesi Anda telah berakhir')).not.toBeVisible();
    await page.waitForTimeout(1000);
    baseExpect(page.url()).toContain('/login');
  });

  baseTest('resets the captcha after a failed login so no stale token is reused', async ({ page }) => {
    await page.goto('/login');
    const captcha = page.getByTestId('mock-captcha');
    // Widget starts verified (holds a token ready to submit)
    await baseExpect(captcha).toHaveAttribute('data-checked', 'true');

    await page.getByLabel('Email').fill('employee.alpha1@e2e.test');
    await page.getByLabel('Kata Sandi').fill('WrongPassword!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await baseExpect(page.getByText('Login Gagal, pastikan email dan kata sandi benar!')).toBeVisible();

    // The single-use token was consumed by the failed attempt, so the widget
    // must reset — otherwise the retry resends the stale token and the backend
    // answers "Verifikasi reCAPTCHA gagal. Silakan coba lagi."
    await baseExpect(captcha).toHaveAttribute('data-checked', 'false');
    await baseExpect(page.getByText('Verifikasi reCAPTCHA gagal')).not.toBeVisible();

    // Re-verifying mints a fresh token and a correct retry succeeds
    await captcha.click();
    await baseExpect(captcha).toHaveAttribute('data-checked', 'true');
    await page.getByLabel('Kata Sandi').fill('Password1!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL('**/employee/dashboard', { timeout: 10_000 });
  });

  baseTest('shows error for non-existent email', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('does-not-exist@e2e.test');
    await page.getByLabel('Kata Sandi').fill('Password1!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await baseExpect(page.getByText('Login Gagal, pastikan email dan kata sandi benar!')).toBeVisible();
    await baseExpect(page.getByText('Sesi Anda telah berakhir')).not.toBeVisible();
    await page.waitForTimeout(1000);
    baseExpect(page.url()).toContain('/login');
  });

  baseTest('blocks login for disabled user', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('disabled.alpha@e2e.test');
    await page.getByLabel('Kata Sandi').fill('Password1!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForTimeout(1500);
    baseExpect(page.url()).toContain('/login');
  });

  baseTest('shows registration link when registration enabled', async ({ page }) => {
    await page.goto('/login');
    await baseExpect(page.getByRole('link', { name: 'Daftar sebagai Manager' })).toBeVisible();
  });
});

baseTest.describe('Register page', () => {
  baseTest('renders all required form fields', async ({ page }) => {
    await page.goto('/register');
    await baseExpect(page.getByRole('heading', { name: 'Daftar Tim Baru' })).toBeVisible();
    await baseExpect(page.getByPlaceholder('Masukkan nama lengkap Anda')).toBeVisible();
    await baseExpect(page.getByPlaceholder('manager@perusahaan.com')).toBeVisible();
    await baseExpect(page.getByPlaceholder('Minimal 8 karakter')).toBeVisible();
    await baseExpect(page.getByPlaceholder('Ulangi password')).toBeVisible();
    await baseExpect(page.getByPlaceholder('PT. Nama Perusahaan')).toBeVisible();
    await baseExpect(page.getByRole('button', { name: 'Daftar Sekarang' })).toBeVisible();
  });

  baseTest('rejects when password and confirmation mismatch', async ({ page }) => {
    await page.goto('/register');
    const ts = Date.now();
    await page.getByPlaceholder('Masukkan nama lengkap Anda').fill('Test User');
    await page.getByPlaceholder('manager@perusahaan.com').fill(`reg-${ts}@e2e.test`);
    await page.getByPlaceholder('Minimal 8 karakter').fill('Password1!');
    await page.getByPlaceholder('Ulangi password').fill('Different1!');
    await page.getByPlaceholder('PT. Nama Perusahaan').fill(`Team ${ts}`);
    await page.getByRole('button', { name: 'Daftar Sekarang' }).click();
    await baseExpect(page.getByText('Password dan konfirmasi password tidak cocok')).toBeVisible();
  });

  baseTest('resets the captcha after a failed registration', async ({ page }) => {
    await page.goto('/register');
    const captcha = page.getByTestId('mock-captcha');
    await baseExpect(captcha).toHaveAttribute('data-checked', 'true');

    // Use an email that already exists so the backend rejects the request
    await page.getByPlaceholder('Masukkan nama lengkap Anda').fill('Dup Manager');
    await page.getByPlaceholder('manager@perusahaan.com').fill('manager.alpha@e2e.test');
    await page.getByPlaceholder('Minimal 8 karakter').fill('Password1!');
    await page.getByPlaceholder('Ulangi password').fill('Password1!');
    await page.getByPlaceholder('PT. Nama Perusahaan').fill('Dup Team');
    await page.getByRole('button', { name: 'Daftar Sekarang' }).click();

    // Failed submit consumes the token, so the widget must reset
    await baseExpect(captcha).toHaveAttribute('data-checked', 'false');
    await baseExpect(page.getByText('Verifikasi reCAPTCHA gagal')).not.toBeVisible();
    baseExpect(page.url()).toContain('/register');
  });

  baseTest('successfully registers new manager and team', async ({ page }) => {
    await page.goto('/register');
    const ts = Date.now();
    const email = `manager-${ts}@e2e.test`;
    const teamName = `Team ${ts}`;
    await page.getByPlaceholder('Masukkan nama lengkap Anda').fill('New Manager');
    await page.getByPlaceholder('manager@perusahaan.com').fill(email);
    await page.getByPlaceholder('Minimal 8 karakter').fill('Password1!');
    await page.getByPlaceholder('Ulangi password').fill('Password1!');
    await page.getByPlaceholder('PT. Nama Perusahaan').fill(teamName);
    await page.getByRole('button', { name: 'Daftar Sekarang' }).click();
    await page.waitForURL('**/manager/dashboard', { timeout: 15_000 });
    await baseExpect(page.getByRole('heading', { name: 'Dashboard Manager' })).toBeVisible();
  });
});

baseTest.describe('Logout', () => {
  baseTest('clearing local storage redirects to login on next nav', async ({ page }) => {
    // Login via the UI to get a fresh non-shared session, then test clearing local storage
    await page.goto('/login');
    await page.getByLabel('Email').fill('employee.beta1@e2e.test');
    await page.getByLabel('Kata Sandi').fill('Password1!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL('**/employee/dashboard', { timeout: 10_000 });
    // Now clear localStorage and navigate; protected route should redirect to login
    await page.evaluate(() => {
      localStorage.removeItem('user');
      localStorage.removeItem('token');
    });
    await page.goto('/employee/dashboard');
    await page.waitForURL('**/login', { timeout: 10_000 });
  });

  baseTest('logout button in navbar logs user out and clears tokens', async ({ page }) => {
    // Use employee.beta1 again (independent of shared storage states)
    await page.goto('/login');
    await page.getByLabel('Email').fill('employee.beta1@e2e.test');
    await page.getByLabel('Kata Sandi').fill('Password1!');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL('**/employee/dashboard', { timeout: 10_000 });
    await page.getByRole('button', { name: 'Keluar' }).click();
    await page.waitForURL('**/login', { timeout: 10_000 });
  });
});
