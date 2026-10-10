import { expect, test } from "@playwright/test";

// BR-P13: the printable price list of a category, as it looks in print (header, footer and buttons hidden).
// The date line changes daily, so it is masked in the snapshot.

test("the price list prints as a clean table", async ({ page }) => {
  await page.goto("/");
  await page.getByRole("link", { name: "المرحلة الإعدادية" }).click();
  await page.getByRole("link", { name: "أولى إعدادي", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "أولى إعدادي" })).toBeVisible();
  const slug = decodeURIComponent(new URL(page.url()).pathname.split("/").at(-1) ?? "");
  await page.goto(`/price-list?category=${encodeURIComponent(slug)}`);

  await expect(page).toHaveURL(/\/price-list\?category=/);
  await expect(page.getByTestId("price-list").getByRole("row")).toHaveCount(17);
  await page.emulateMedia({ media: "print" });

  await expect(page.getByRole("button", { name: "طباعة" })).toBeHidden();
  await expect(page.getByRole("banner")).toBeHidden();
  await expect(page).toHaveScreenshot("price-list-print.png", {
    fullPage: true,
    mask: [page.getByTestId("price-list-meta")],
  });
});

test("the share button copies the list's link", async ({ page }) => {
  await page.goto("/price-list?q=رياضيات");

  await page.getByRole("button", { name: "مشاركة الرابط" }).click();

  await expect(page.getByText("تم نسخ الرابط")).toBeVisible();
});
