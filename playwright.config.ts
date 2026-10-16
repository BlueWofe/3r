import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.BASE_URL ?? 'http://localhost:3180',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'api', testMatch: '**/*.api.spec.ts' },
    { name: 'desktop', testMatch: '**/*.ui.spec.ts', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', testMatch: '**/*.ui.spec.ts', use: { ...devices['iPhone 13'] } },
  ],
  outputDir: 'test-results',
});
