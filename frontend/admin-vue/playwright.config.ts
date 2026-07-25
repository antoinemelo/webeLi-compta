import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  outputDir: 'test-results/artifacts',
  reporter: [['list'], ['junit', { outputFile: 'test-results/e2e-junit.xml' }]],
  use: {
    baseURL: 'http://127.0.0.1:8093/e2e',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure'
  },
  webServer: {
    command: 'node ./tests/e2e/serve.mjs',
    url: 'http://127.0.0.1:8093/e2e/login',
    timeout: 30_000,
    reuseExistingServer: false
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }]
});
