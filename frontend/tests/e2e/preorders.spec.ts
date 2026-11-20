import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession, uniqueSuffix } from "./helpers";

// V2 Task 10 (BR-O14, D88): a shopper pre-orders a Coming Soon book from its page, confirms the pre-order from the cart;
// the merchant activates the book, adds stock and converts the pre-orders from the product page; the shopper sees the
// normal order their pre-order became. The book is made in the run, so the seeded catalog stays as it is.

type Id = { id: number; status?: string; children?: Id[] };
type Product = { id: number; slug: string; sku: { id: number } };
type Detail = { number: number; kind: string; preorder_state: string | null; conversions: { number: number }[] };

test("BR-O14 a pre-order becomes a normal order once the book arrives", async ({ page, browser }) => {
  await loginAsOwner(page);
  const categories = (await api<{ data: Id[] }>(page, "GET", "/api/dashboard/categories")).body.data;
  const units = (await api<{ data: Id[] }>(page, "GET", "/api/dashboard/units")).body.data;
  const category = categories.find((c) => c.status === "active") ?? categories[0];
  const unit = units.find((u) => u.status === "active") ?? units[0];
  expect(category).toBeDefined();
  expect(unit).toBeDefined();
  const suffix = uniqueSuffix();
  const name = `سلاح التلميذ طبعة ${suffix}`;
  const created = await api<{ data: Product }>(page, "POST", "/api/dashboard/products", {
    name,
    category_id: category!.id,
    status: "coming_soon",
    preorder: { enabled: true, expected_at: "2027-01-15", limit: 50 },
    sku: {
      code: `PRE-${suffix}`,
      base_unit_id: unit!.id,
      units: [{ unit_id: unit!.id, conversion_factor: 1, base_price: "90.00", moq: 2 }],
    },
  });
  expect(created.status).toBe(201);
  const product = created.body.data;

  // The shopper books it from the product page and confirms from the cart (no message: they opted out of them).
  const shopper = await shopperSession(browser);
  expect(
    (await api(shopper.page, "PATCH", "/api/store/account/preferences", { order_notifications: false })).status,
  ).toBeLessThan(300);
  await shopper.page.goto(`/p/${encodeURIComponent(product.slug)}`);
  await expect(shopper.page.getByTestId("preorder-terms")).toContainText("السعر النهائي عند التوفر");
  await shopper.page.getByRole("button", { name: "احجز الآن" }).click();
  await shopper.page.goto("/cart");
  const section = shopper.page.getByTestId("preorders");
  await expect(section).toContainText(name);
  await expect(shopper.page.getByTestId("preorders-total")).toContainText("180.00");
  // The section fits the screen (the store header's category strip overflows on its own, unrelated to the cart).
  const box = await section.boundingBox();
  expect(
    (box?.x ?? -1) >= 0 && (box?.x ?? 0) + (box?.width ?? Infinity) <= (shopper.page.viewportSize()?.width ?? 0),
  ).toBe(true);
  await section.getByRole("link", { name: "تأكيد الحجز" }).click();
  await expect(shopper.page.getByRole("heading", { name: "تأكيد الحجز المسبق" })).toBeVisible();
  await expect(shopper.page.getByRole("radio", { name: /المكتبة/ })).toBeChecked();
  await shopper.page.getByRole("button", { name: "تأكيد الحجز" }).click();
  await expect(shopper.page.getByRole("heading", { name: "تم تسجيل حجزك" })).toBeVisible();
  const preorderNumber = Number(await shopper.page.getByTestId("order-number").textContent());

  // The merchant sees the demand, activates the book and adds stock, then converts.
  await page.goto(`/dashboard/products/${product.id}`);
  await expect(page.getByTestId("preorder-demand")).toContainText("2");
  expect((await api(page, "PATCH", `/api/dashboard/products/${product.id}/status`, { status: "active" })).status).toBe(
    200,
  );
  expect(
    (
      await api(page, "POST", `/api/dashboard/inventory/${product.sku.id}/adjust`, {
        new_on_hand: 10,
        reason: "وصول الطبعة",
      })
    ).status,
  ).toBeLessThan(300);
  await page.reload();
  await page.getByRole("button", { name: "تحويل الحجوزات إلى طلبات" }).click();
  const summary = page.getByTestId("conversion-summary");
  await expect(summary).toContainText(`من الحجز #${preorderNumber}`);

  // The shopper's pre-order is converted and links to the normal order, which waits for confirmation.
  const preorder = (await api<{ data: Detail }>(shopper.page, "GET", `/api/store/orders/${preorderNumber}`)).body.data;
  expect(preorder.preorder_state).toBe("converted");
  const orderNumber = preorder.conversions[0]?.number ?? 0;
  await shopper.page.goto(`/account/orders/${preorderNumber}`);
  await expect(shopper.page.getByTestId("order-status")).toHaveText("تم التحويل إلى طلب");
  await shopper.page
    .getByTestId("conversions")
    .getByRole("link", { name: `طلب رقم ${orderNumber}` })
    .click();
  await expect(shopper.page.getByRole("heading", { name: `طلب رقم ${orderNumber}` })).toBeVisible();
  await expect(shopper.page.getByTestId("converted-from")).toContainText(String(preorderNumber));
  await shopper.close();

  // The merchant's book is now a normal product with the converted units reserved.
  const stock = (
    await api<{ data: { on_hand: number; reserved: number } }>(
      page,
      "GET",
      `/api/dashboard/inventory/${product.sku.id}`,
    )
  ).body.data;
  expect(stock).toMatchObject({ on_hand: 10, reserved: 2 });
});
