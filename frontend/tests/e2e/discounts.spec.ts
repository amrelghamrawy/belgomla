import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession } from "./helpers";

// V2 Task 08 (BR-PR08, D85): a shopper's order gets a line discount and an order discount from the merchant before it
// is confirmed; delivering and collecting in full then collects exactly the discounted total, and the customer sees
// the discount (not its reason).

type Detail = {
  order_total: string;
  items_total: string;
  discount: { type: string; value: string; amount: string } | null;
  items: { list_unit_price: string; unit_price: string }[];
};

test("BR-PR08 the merchant discounts an order, then delivers and collects the discounted total", async ({
  page,
  browser,
}) => {
  const shopper = await shopperSession(browser);
  const { checkout } = await shopper.order("الأضواء رياضيات ثانية إعدادي");
  expect(checkout.status).toBe(201);
  const number = checkout.body.data?.order.number ?? 0;

  await loginAsOwner(page);
  await page.goto(`/dashboard/orders/${number}`);
  await expect(page.getByRole("heading", { level: 1, name: `طلب #${number}` })).toBeVisible();

  await page.getByRole("button", { name: "خصم" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByTestId("discount-lines").getByRole("textbox").first().fill("10");
  await dialog.getByLabel("خصم خصم على إجمالي الطلب").fill("5");
  await dialog.getByLabel("نوع الخصم: خصم على إجمالي الطلب").selectOption("amount");
  await dialog.getByLabel("سبب خصم الطلب").fill("عميل دائم");
  const shownTotal = (await dialog.getByTestId("discount-order-total").textContent()) ?? "";
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  await dialog.getByRole("button", { name: "حفظ الخصم" }).click();

  await expect(dialog).toBeHidden();
  await expect(page.getByTestId("order-discount")).toContainText("5.00 ج.م");
  await expect(page.getByLabel("قبل الخصم").first()).toBeVisible();

  const mine = await api<{ data: Detail }>(shopper.page, "GET", `/api/store/orders/${number}`);
  expect(mine.body.data.discount).toEqual({ type: "amount", value: "5.00", amount: "5.00" });
  expect(Number(mine.body.data.items[0]?.unit_price)).toBeLessThan(Number(mine.body.data.items[0]?.list_unit_price));
  const money = (amount: string) => `${Number(amount).toLocaleString("en-US", { minimumFractionDigits: 2 })} ج.م`;
  expect(shownTotal).toBe(money(mine.body.data.order_total));
  await shopper.close();

  await page.getByRole("button", { name: "تأكيد الطلب" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("مؤكد");
  await page.getByRole("button", { name: "تسليم وتحصيل كامل" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("تم التوصيل");
  await expect(page.getByTestId("collection-status")).toHaveText("محصل");
  await expect(page.getByTestId("order-balance")).toHaveText("0.00 ج.م");
  await expect(page.getByTestId("order-collections")).toContainText(money(mine.body.data.order_total));
});
