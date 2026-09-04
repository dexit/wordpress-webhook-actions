// Visual/layout regression checks against the *running* local WP install
// (see /home/mati/Work/flowsystems-webhook-actions docker-compose + Makefile —
// `make setup` provisions it, `make up` starts it). This does NOT build or
// serve the SPA itself: it drives the real admin.php page, so `admin/dist`
// must be current (see fswa-deploy's build-current check).
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.FSWA_BASE_URL || 'https://webhook-actions.local';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list']],
  outputDir: './e2e/.results',
  use: {
    baseURL,
    ignoreHTTPSErrors: true, // local mkcert/self-signed cert
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 }, storageState: './e2e/.auth/admin.json' },
      dependencies: ['setup'],
    },
    {
      name: 'mobile',
      // iPhone 12 viewport/UA/touch emulation, but forced onto Chromium
      // (the only engine this box has installed) rather than the preset's
      // default WebKit — device emulation, not real WebKit rendering, is
      // enough for the layout checks this suite runs.
      use: {
        ...devices['iPhone 12'],
        defaultBrowserType: undefined,
        browserName: 'chromium',
        storageState: './e2e/.auth/admin.json',
      },
      dependencies: ['setup'],
    },
  ],
});
