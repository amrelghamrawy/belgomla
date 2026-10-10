import { expect, test } from "@playwright/test";

// The seeded tenant (backend DatabaseSeeder): alnoor / مكتبة النور, owner@alnoor.test / password.

test("the store home shows the tenant name in an Arabic right-to-left page", async ({ page }) => {
  await page.goto("/");

  await expect(page.getByTestId("tenant-name")).toHaveText("مكتبة النور");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await expect(page.locator("html")).toHaveAttribute("lang", "ar");
  await expect(page).toHaveTitle("مكتبة النور");
});

test("a merchant logs in to the dashboard and sees who they are", async ({ page }) => {
  await page.goto("/dashboard/login");

  await page.getByLabel("البريد الإلكتروني").fill("owner@alnoor.test");
  await page.getByLabel("كلمة المرور").fill("password");
  await page.getByRole("button", { name: "دخول" }).click();

  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByTestId("tenant-name")).toHaveText("مكتبة النور");

  await page.getByRole("button", { name: "تسجيل الخروج" }).click();
  await expect(page).toHaveURL(/\/dashboard\/login$/);
});

test("a wrong password shows the Arabic message from the error envelope", async ({ page }) => {
  await page.goto("/dashboard/login");

  await page.getByLabel("البريد الإلكتروني").fill("owner@alnoor.test");
  await page.getByLabel("كلمة المرور").fill("wrong-password");
  await page.getByRole("button", { name: "دخول" }).click();

  // Scoped to <main>: Next adds its own role="alert" route announcer to every page.
  await expect(page.getByRole("main").getByRole("alert")).toHaveText("البريد الإلكتروني أو كلمة المرور غير صحيحة");
  await expect(page).toHaveURL(/\/dashboard\/login$/);
});

test("the dashboard sends a guest to the login page", async ({ page }) => {
  await page.goto("/dashboard");

  await expect(page).toHaveURL(/\/dashboard\/login\?next=%2Fdashboard$/);
});
