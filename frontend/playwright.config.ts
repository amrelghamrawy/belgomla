import { defineConfig, devices } from "@playwright/test";

// Smoke flows against the real stack (rules/03 §6): Laravel on :8000 and Next on :3000, reached through the
// tenant host, with a migrated + seeded backend database. Chromium maps *.gomal.test to 127.0.0.1 itself,
// so no hosts entry is needed for the e2e run. Manual gate until CI exists.
const baseURL = process.env.E2E_BASE_URL ?? "http://alnoor.gomal.test:3000";
const launchOptions = { args: ["--host-resolver-rules=MAP *.gomal.test 127.0.0.1"] };

export default defineConfig({
  testDir: "./tests/e2e",
  fullyParallel: false,
  retries: 0,
  reporter: [["list"]],
  use: {
    baseURL,
    locale: "ar-EG",
    timezoneId: "Africa/Cairo",
    trace: "retain-on-failure",
    launchOptions,
  },
  projects: [
    {
      name: "mobile",
      use: { ...devices["Desktop Chrome"], viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
    },
    { name: "desktop", use: { ...devices["Desktop Chrome"], viewport: { width: 1280, height: 800 } } },
  ],
  webServer: [
    {
      command: "php artisan serve --host=127.0.0.1 --port=8000",
      cwd: "../backend",
      url: "http://127.0.0.1:8000/up",
      reuseExistingServer: true,
      timeout: 60_000,
    },
    {
      command: "npm run dev",
      url: "http://127.0.0.1:3000",
      reuseExistingServer: true,
      timeout: 120_000,
    },
  ],
});
