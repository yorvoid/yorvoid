import { defineConfig } from '@playwright/test';
import base from './playwright.config.ts';

/**
 * The quickstart's application, as tools/quickstart/follow.php left it (PLT-080).
 */
export default defineConfig({
  ...base,
  testDir: 'quickstart',
  outputDir: '../var/quickstart/results',
});
