import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession } from "./helpers";

// V2 Task 09 (BR-O12, BR-O13, D86, D87): the merchant edits a shopper's pending order (one more carton of its line and
// a second book), delivers the new book one carton short, and records a return of what was delivered. The stock
// follows each step and the customer sees the delivered quantity and the changed line.

const PRODUCT = "الأضواء رياضيات ثانية إعدادي";
type Row = {
  sku_id: number;
  code: string;
  name: string;
  status: string;
  available: number;
  moq: number;
  conversion_factor: number;
};
type Line = { id: number; sku_id: number; quantity: number; delivered_quantity: number | null; line_total: string };
type Detail = { order_total: string; items: Line[]; changes: { change: string }[] };
type Stock = { on_hand: number; reserved: number; conversion_factor: number };

test("BR-O12 BR-O13 the merchant edits an order, delivers a line short, then takes a return", async ({
  page,
  browser,
}) => {
  const shopper = await shopperSession(browser);
  const { checkout, sku } = await shopper.order(PRODUCT);
  expect(checkout.status).toBe(201);
  const number = checkout.body.data?.order.number ?? 0;
  await loginAsOwner(page);
  // A second active book with stock for its MOQ, other than the ordered one.
  const rows = (await api<{ data: Row[] }>(page, "GET", "/api/dashboard/inventory?per_page=100")).body.data;
  const row = rows.find(
    (r) => r.sku_id !== sku.id && r.status === "active" && r.available >= 2 * r.moq * r.conversion_factor,
  );
  expect(row).toBeDefined();
  const second = { name: row!.name, sku: { id: row!.sku_id, code: row!.code, moq: row!.moq } };
  const stock = async (id: number) =>
    (await api<{ data: Stock }>(page, "GET", `/api/dashboard/inventory/${id}`)).body.data;
  const before = await stock(second!.sku.id);
  await page.goto(`/dashboard/orders/${number}`);
  await expect(page.getByRole("heading", { level: 1, name: `طلب #${number}` })).toBeVisible();

  // Edit: one more carton of the line, and a second book at its MOQ.
  await page.getByRole("button", { name: "تعديل الأصناف" }).click();
  const dialog = page.getByRole("dialog");
  const firstQuantity = dialog.getByLabel(/^كمية /).first();
  const ordered = Number(await firstQuantity.inputValue());
  await firstQuantity.fill(String(ordered + 1));
  await dialog.getByRole("button", { name: "إضافة صنف" }).click();
  await page.getByPlaceholder("ابحث باسم المنتج أو الكود").fill(second!.sku.code);
  await page.getByRole("option", { name: new RegExp(second!.sku.code) }).click();
  await expect(dialog.getByTestId("edit-changes")).toContainText(`إضافة ${second!.name}`);
  await expect(dialog.getByTestId("edit-changes")).toContainText(`من ${ordered} إلى ${ordered + 1}`);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  const save = dialog.getByRole("button", { name: "حفظ التعديل" });
  await expect(save).toBeEnabled();
  await save.click();

  await expect(dialog).toBeHidden();
  await expect(page.getByTestId("order-changes")).toContainText(`أُضيف ${second!.name}`);
  const moq = second!.sku.moq;
  expect((await stock(second!.sku.id)).reserved).toBe(before.reserved + moq * before.conversion_factor);

  // Deliver the new book one carton short (BR-O13): the rest goes back to stock.
  await page.getByRole("button", { name: "تأكيد الطلب" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("مؤكد");
  await page.getByRole("button", { name: "تم التسليم", exact: true }).click();
  const deliver = page.getByRole("dialog");
  await deliver.getByLabel("تسليم جزئي؟").click();
  await deliver.getByLabel(`الكمية المسلَّمة من ${second!.name}`).fill(String(moq - 1));
  await deliver.getByRole("button", { name: "تأكيد التسليم" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("تم التوصيل");

  const after = await stock(second!.sku.id);
  expect(after.reserved).toBe(before.reserved);
  expect(after.on_hand).toBe(before.on_hand - (moq - 1) * before.conversion_factor);

  // The customer sees what was delivered and the changed line.
  const mine = await api<{ data: Detail }>(shopper.page, "GET", `/api/store/orders/${number}`);
  const added = mine.body.data.items.find((item) => item.sku_id === second!.sku.id);
  expect(added).toMatchObject({ quantity: moq, delivered_quantity: moq - 1 });
  expect(mine.body.data.changes.map((change) => change.change)).toEqual(["quantity", "added", "delivered_short"]);
  await shopper.page.goto(`/account/orders/${number}`);
  await expect(shopper.page.getByTestId(`delivered-${added!.id}`)).toBeVisible();
  await shopper.close();

  // A return takes back at most what was delivered (BR-R02).
  const returnable = await api<{ returnable: { order_item_id: number; delivered: number; returnable: number }[] }>(
    page,
    "GET",
    `/api/dashboard/orders/${number}/returns`,
  );
  expect(returnable.body.returnable.find((line) => line.order_item_id === added!.id)).toMatchObject({
    delivered: moq - 1,
    returnable: moq - 1,
  });
  await page.getByRole("button", { name: "تسجيل مرتجع" }).click();
  const returns = page.getByRole("dialog");
  await returns
    .getByRole("button", { name: /^الكمية المرتجعة \+/ })
    .first()
    .click();
  await returns.getByLabel("سبب المرتجع").fill("تالف");
  await returns.getByRole("button", { name: "تسجيل مرتجع" }).click();
  await expect(page.getByTestId("order-returns")).toContainText("مرتجع #1");
});
