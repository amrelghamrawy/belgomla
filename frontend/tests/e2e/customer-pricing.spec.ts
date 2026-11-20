import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession, uniqueSuffix } from "./helpers";

// V2 Task 11 (BR-PR12..PR14, D89): the merchant creates a customer group with 50% off the base price and puts a
// shopper in it from the customers list; the shopper then sees the group price with «سعرك الخاص» on the storefront,
// and checking out snapshots it as the group price. The group is archived at the end, so the shopper's prices return.

const BOOK = "الأضواء رياضيات ثانية إعدادي";

type Product = { slug: string; sku: { base_price: string; price_source?: string } };
type Detail = { items: { unit_price: string; list_unit_price: string; price_source: string }[] };

test("BR-PR14 a shopper put in a group sees and pays the group price", async ({ page, browser }) => {
  const shopper = await shopperSession(browser);
  const before = await api<{ data: Product[] }>(
    shopper.page,
    "GET",
    `/api/store/products?q=${encodeURIComponent(BOOK)}`,
  );
  const product = before.body.data[0];
  expect(product?.sku.price_source).toBeUndefined();
  const groupPrice = (Number(product?.sku.base_price) / 2).toFixed(2);
  const name = `مستوى اختبار ${uniqueSuffix()}`;

  await loginAsOwner(page);
  await page.goto("/dashboard/customer-groups");
  await page.getByRole("button", { name: "مستوى جديد" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("اسم المستوى").fill(name);
  await dialog.getByLabel("نسبة الخصم من السعر الأساسي (%)").fill("50");
  await dialog.getByRole("button", { name: "حفظ" }).click();
  await expect(dialog).toBeHidden();
  await expect(page.getByRole("row", { name: new RegExp(name) })).toContainText("50%");
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

  await page.goto(`/dashboard/customers?q=${shopper.phone.slice(1)}`);
  await page.getByRole("checkbox", { name: "تحديد مكتبة اختبار اللوحة" }).check();
  await page.getByRole("combobox", { name: "تعيين إلى مستوى" }).selectOption({ label: name });
  await page.getByRole("button", { name: "تعيين المحددين" }).click();
  await expect(page.getByText("تم تعيين 1 عميل")).toBeVisible();
  await expect(page.getByRole("row", { name: /مكتبة اختبار اللوحة/ })).toContainText(name);

  await shopper.page.goto(`/p/${encodeURIComponent(product?.slug ?? "")}`);
  await expect(shopper.page.getByTestId("your-price").first()).toHaveText("سعرك الخاص");
  const after = await api<{ data: Product[] }>(
    shopper.page,
    "GET",
    `/api/store/products?q=${encodeURIComponent(BOOK)}`,
  );
  expect(after.body.data[0]?.sku).toMatchObject({ base_price: groupPrice, price_source: "group" });

  const { checkout } = await shopper.order(BOOK);
  expect(checkout.status).toBe(201);
  const mine = await api<{ data: Detail }>(
    shopper.page,
    "GET",
    `/api/store/orders/${checkout.body.data?.order.number}`,
  );
  expect(mine.body.data.items[0]).toMatchObject({
    unit_price: groupPrice,
    list_unit_price: groupPrice,
    price_source: "group",
  });

  await page.goto("/dashboard/customer-groups");
  await page
    .getByRole("row", { name: new RegExp(name) })
    .getByRole("button", { name: "أرشفة" })
    .click();
  await expect(page.getByRole("row", { name: new RegExp(name) })).toContainText("مؤرشف");
  const restored = await api<{ data: Product[] }>(
    shopper.page,
    "GET",
    `/api/store/products?q=${encodeURIComponent(BOOK)}`,
  );
  expect(restored.body.data[0]?.sku.base_price).toBe(product?.sku.base_price);
  await shopper.close();
});
