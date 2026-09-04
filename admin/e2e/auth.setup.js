import { test as setup } from '@playwright/test';

// Logs into the local WP install once and reuses the cookie across the
// desktop + mobile projects, instead of every test doing its own wp-login.
const ADMIN_USER = process.env.FSWA_WP_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.FSWA_WP_ADMIN_PASSWORD || 'admin';

setup('authenticate', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin\/?$/);
  await page.context().storageState({ path: './e2e/.auth/admin.json' });
});
