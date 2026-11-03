import { defineConfig, devices } from '@playwright/test';

/**
 * Chromium for every test: it's the browser whose virtual authenticator Playwright can drive. Firefox
 * for the tests that need no authenticator, tagged @any-browser: the pages, their forms and scripts. One
 * test at a time, against one database the tests reset.
 */
export default defineConfig({
  testDir: 'tests',
  fullyParallel: false,
  workers: 1,
  forbidOnly: process.env['CI'] !== undefined,
  reporter: [['list']],
  outputDir: '../var/e2e/results',
  use: {
    baseURL: 'http://localhost:8000',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] }, grep: /@any-browser/ },
  ],
});
