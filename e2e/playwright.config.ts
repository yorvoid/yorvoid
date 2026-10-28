import { defineConfig, devices } from '@playwright/test';

/**
 * Chromium only: it's the browser whose virtual authenticator Playwright can drive. One test at a
 * time, against one database the tests reset.
 */
export default defineConfig({
  testDir: 'tests',
  fullyParallel: false,
  workers: 1,
  forbidOnly: process.env['CI'] !== undefined,
  reporter: [['list']],
  outputDir: '../var/e2e/results',
  use: {
    ...devices['Desktop Chrome'],
    baseURL: 'http://localhost:8000',
    trace: 'retain-on-failure',
  },
});
