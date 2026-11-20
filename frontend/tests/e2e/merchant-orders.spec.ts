import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession } from "./helpers";

// V2 Task 07 (BR-O11): an order that arrives by phone. The merchant picks the customer, the address and a product,
// sees the live quote, and places the order without sending the customer a message (the dev store may have a real
// WhatsApp number linked); the order opens marked as placed by the merchant and the customer sees it in their account.

const PRODUCT = "الأضواء رياضيات ثانية إعدادي";

type StoreProduct = { name: string; sku: { code: string; base_price: string; moq: number } };

test("BR-O11 a merchant places a phone order for a customer, who then sees it", async ({ page, browser }) => {
  const shopper = await shopperSession(browser);
  const products = await api<{ data: StoreProduct[] }>(
    shopper.page,
    "GET",
    `/api/store/products?q=${encodeURIComponent(PRODUCT)}`,
  );
  const sku = products.body.data[0]?.sku;
  expect(sku).toBeDefined();
  // Enough cartons to pass the store's minimum order value, as the shopper helper does.
  const quantity = Math.max(sku!.moq, Math.ceil(600 / Number(sku!.base_price)));

  await loginAsOwner(page);
  await page.goto("/dashboard/orders");
  await page.getByRole("link", { name: "طلب جديد" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "طلب جديد لعميل" })).toBeVisible();

  await page.getByRole("combobox", { name: "العميل" }).click();
  await page.getByPlaceholder("ابحث بالاسم أو رقم الهاتف").fill(shopper.phone.slice(-7));
  await page.getByRole("option", { name: new RegExp(shopper.phone.slice(-7)) }).click();
  await expect(page.getByLabel("عنوان التوصيل")).toContainText("المكتبة");

  await page.getByRole("button", { name: "إضافة صنف" }).click();
  await page.getByPlaceholder("ابحث باسم المنتج أو الكود").fill(sku!.code);
  await page.getByRole("option", { name: new RegExp(sku!.code) }).click();
  const quantityField = page.getByLabel(/^كمية /);
  await quantityField.fill(String(quantity));
  await expect(page.getByText(/^المتاح \d+/)).toBeVisible();
  // The line table scrolls inside its card on a phone; the page itself never scrolls sideways (rules/03 §3).
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

  await page.getByRole("switch", { name: "إرسال رسالة للعميل" }).click();
  await page.getByLabel("ملاحظة على الطلب (يراها العميل)").fill("طلب بالتليفون");
  const submit = page.getByRole("button", { name: "تسجيل الطلب" });
  await expect(submit).toBeEnabled();
  await submit.click();

  await expect(page).toHaveURL(/\/dashboard\/orders\/\d+$/);
  const number = Number(page.url().split("/").at(-1));
  await expect(page.getByRole("heading", { level: 1, name: `طلب #${number}` })).toBeVisible();
  await expect(page.getByTestId("placed-by-merchant").first()).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

  const mine = await api<{ data: { number: number; customer_note: string | null }[] }>(
    shopper.page,
    "GET",
    "/api/store/orders",
  );
  expect(mine.body.data[0]).toMatchObject({ number, customer_note: "طلب بالتليفون" });
  await shopper.close();
});
