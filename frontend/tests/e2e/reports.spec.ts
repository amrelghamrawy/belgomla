import { expect, type Page, test } from "@playwright/test";

import { loginAsOwner } from "./helpers";

// V2 Task 16 (D94): the reports page's tabs on the demo store (alnoor has about two months of delivered orders).
// Read-only: it changes no data. Every tab must load its table, say when it was computed, export a file and fit the
// screen without sideways scroll, on a phone and a desktop.
test.describe.configure({ timeout: 120_000 });

async function noSidewaysScroll(page: Page, label: string) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, label).toBeLessThanOrEqual(0);
}

async function exportsFile(page: Page, report: string) {
  const download = page.waitForEvent("download");
  await page.getByRole("tabpanel").getByRole("button", { name: "تصدير", exact: true }).click();
  expect((await download).suggestedFilename()).toMatch(
    new RegExp(`^report-${report}-\\d{4}-\\d{2}-\\d{2}-\\d{4}-\\d{2}-\\d{2}\\.xlsx$`),
  );
}

test("the owner opens every report tab on the demo store and exports each report", async ({ page }) => {
  await loginAsOwner(page);
  await page.goto("/dashboard/reports");
  await expect(page.getByRole("main").getByRole("heading", { level: 1, name: "التقارير" })).toBeVisible();

  // نظرة عامة: the V1 overview cards and charts.
  await expect(page.getByRole("tab", { name: "نظرة عامة" })).toHaveAttribute("aria-selected", "true");
  await expect(page.getByTestId("status")).toBeVisible();
  await noSidewaysScroll(page, "overview");

  // المبيعات: the season preset widens the range for every tab (it stays in the URL).
  await page.getByRole("tab", { name: "المبيعات" }).click();
  await page.getByRole("button", { name: "هذا الموسم" }).click();
  await expect(page).toHaveURL(/tab=sales/);
  await expect(page).toHaveURL(/from=\d{4}-08-01/);
  await expect(page.getByRole("button", { name: "هذا الموسم" })).toHaveAttribute("aria-pressed", "true");
  // D104: the period rows fold under the chart; open them.
  await page.getByText(/عرض الجدول التفصيلي/).click();
  await expect(page.getByTestId("sales-buckets")).toBeVisible();
  await expect(page.getByTestId("sales-net")).toBeVisible();
  await expect(page.getByRole("figure", { name: /رسم بياني لصافي المبيعات/ })).toBeVisible();
  await expect(page.getByTestId("generated-at")).toContainText("آخر تحديث");
  await noSidewaysScroll(page, "sales");
  await exportsFile(page, "sales-analytics");

  // المنتجات: the top 20 and the table, with the bundles report kept in this tab.
  await page.getByRole("tab", { name: "المنتجات" }).click();
  await expect(page).toHaveURL(/tab=products/);
  await expect(page.getByTestId("products-report")).toBeVisible();
  await expect(page.getByTestId("top-products").getByRole("link").first()).toBeVisible();
  await expect(page.getByText("الباقات المباعة")).toBeVisible();
  await noSidewaysScroll(page, "products");
  await exportsFile(page, "products");

  // العملاء: the best customers, then the dormant view.
  await page.getByRole("tab", { name: "العملاء" }).click();
  await expect(page.getByTestId("customers-report")).toBeVisible();
  await noSidewaysScroll(page, "customers");
  await exportsFile(page, "customers");
  await page.getByRole("button", { name: "العملاء الخاملون" }).click();
  await expect(page).toHaveURL(/view=dormant/);
  await expect(page.getByRole("button", { name: "العملاء الخاملون" })).toHaveAttribute("aria-pressed", "true");
  await expect(page.getByLabel("جاري التحميل…")).toHaveCount(0);

  // المخزون: stock valued at the selling price (D94), the table and the low-stock list.
  await page.getByRole("tab", { name: "المخزون" }).click();
  await expect(page.getByTestId("inventory-report")).toBeVisible();
  await expect(page.getByTestId("stock-value")).toBeVisible();
  await expect(page.getByText("قيمة المخزون بسعر البيع").first()).toBeVisible();
  await expect(page.getByText("أصناف قاربت على النفاد")).toBeVisible();
  await noSidewaysScroll(page, "inventory");
  await exportsFile(page, "inventory");

  // التحصيل: the tiles, the aging now, the buckets and who owes.
  await page.getByRole("tab", { name: "التحصيل" }).click();
  await expect(page.getByTestId("collected-net")).toBeVisible();
  await expect(page.getByRole("figure", { name: "التحصيل حسب الفترة" })).toBeVisible();
  await page.getByText(/عرض الجدول التفصيلي/).click();
  await expect(page.getByTestId("collections-buckets")).toBeVisible();
  await expect(page.getByText("أعمار المديونيات الآن")).toBeVisible();
  await expect(page.getByText("المستحق لكل عميل")).toBeVisible();
  await expect(page.getByLabel("جاري التحميل…")).toHaveCount(0);
  await noSidewaysScroll(page, "collections");
  await exportsFile(page, "collections");
});
