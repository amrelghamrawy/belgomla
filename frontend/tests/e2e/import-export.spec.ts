import { execFileSync } from "node:child_process";
import path from "node:path";

import { expect, test } from "@playwright/test";

import { loginAsOwner } from "./helpers";

// V2 Task 06: the merchant exports the catalog and imports the very same file back. The file is the import format,
// so every row is an update that changes nothing: the summary shows no error and the seeded catalog stays as it is.
// php artisan serve runs no queue, so the spec drains the `default` queue once the import is started. The store
// number is pointed at a dead gateway for that run, so nothing can reach a real WhatsApp number.
test.describe.configure({ timeout: 120_000 });

function drainImportQueue(): void {
  execFileSync("php", ["artisan", "queue:work", "redis", "--queue=default", "--stop-when-empty", "--tries=1"], {
    cwd: path.resolve(process.cwd(), "../backend"),
    env: { ...process.env, WA_GATEWAY_ENABLED: "false", WA_GATEWAY_URL: "http://127.0.0.1:1" },
    stdio: "ignore",
    timeout: 90_000,
  });
}

test("a merchant exports the catalog and imports the same file back without errors", async ({ page }, testInfo) => {
  await loginAsOwner(page);
  await page.goto("/dashboard/products");

  const downloading = page.waitForEvent("download");
  await page.getByRole("button", { name: "تصدير" }).click();
  const download = await downloading;
  expect(download.suggestedFilename()).toMatch(/^products-\d{4}-\d{2}-\d{2}\.xlsx$/);
  const file = testInfo.outputPath(download.suggestedFilename());
  await download.saveAs(file);

  await page.getByRole("button", { name: "استيراد" }).click();
  const wizard = page.getByRole("dialog");
  await wizard.getByLabel("الملف").setInputFiles(file);
  await wizard.getByRole("button", { name: "فحص الملف" }).click();
  await expect(wizard.getByText("لا توجد أخطاء، الملف جاهز للاستيراد")).toBeVisible({ timeout: 60_000 });
  await expect(wizard.getByTestId("import-preview")).toContainText("منتجات جديدة0");

  await wizard.getByRole("button", { name: /^استيراد \d+ منتج$/ }).click();
  await expect(wizard.getByTestId("import-progress")).toBeVisible();
  drainImportQueue();

  await expect(wizard.getByRole("status")).toHaveText("اكتمل الاستيراد", { timeout: 30_000 });
  const summary = wizard.getByTestId("import-summary");
  await expect(summary).toContainText("تم تخطيها لوجود أخطاء0");
  await expect(summary).toContainText("تعذر حفظها0");
  await expect(summary.getByTestId("import-errors")).toHaveCount(0);
});
