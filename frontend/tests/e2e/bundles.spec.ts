import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession, uniqueSuffix } from "./helpers";

// V2 Task 15 (BR-P15, D93): the merchant generates a grade set from the facet selector (أولى إعدادي + الأضواء of the
// seeded catalog), lands on the draft bundle's editor (inactive until activated), names it for this run, prices it
// 10 % off the sum and activates it. A shopper finds it, sees its components, adds enough sets for the minimum order
// value and checks out; the order shows the bundle line, which opens to its components. The bundle is set inactive
// at the end so the seeded catalog other specs use stays as it is.
test.describe.configure({ timeout: 120_000 });

type Component = { sku_id: number; product_name: string; quantity: number; conversion_factor: number };
type Bundle = {
  id: number;
  slug: string;
  status: string;
  sku: { base_price: string; moq: number };
  bundle: { components: Component[]; availability: number };
};
type Stock = { on_hand: number; reserved: number; available: number };
type OrderItem = {
  id: number;
  is_bundle: boolean;
  parent_item_id: number | null;
  product_name: string;
  quantity: number;
};

test("BR-P15 a merchant generates a grade set, activates it and a shopper buys it", async ({ page, browser }) => {
  const name = `طقم اختبار ${uniqueSuffix()}`;
  await loginAsOwner(page);

  // The wizard from the products page's facet selector.
  await page.goto("/dashboard/products");
  await page.getByRole("button", { name: "إنشاء باقة صف دراسي" }).click();
  const wizard = page.getByRole("dialog");
  await wizard.getByLabel("السنة الدراسية").selectOption({ label: "أولى إعدادي" });
  await wizard.getByLabel("الشركة").selectOption({ label: "الأضواء" });
  await wizard.getByRole("button", { name: "إنشاء الباقة" }).click();

  await expect(page).toHaveURL(/\/dashboard\/bundles\/\d+$/);
  const id = Number(new URL(page.url()).pathname.split("/").pop());
  await expect(page.getByTestId("bundle-draft")).toContainText("غير نشطة");
  await expect(page.getByTestId("bundle-components").getByRole("listitem").first()).toBeVisible();

  // Enough stock on every component for the sets bought below (the dev catalog may have been bought down).
  const generated = (await api<{ data: Bundle }>(page, "GET", `/api/dashboard/products/${id}`)).body.data;
  expect(generated.status).toBe("inactive");
  expect(generated.bundle.components.length).toBeGreaterThan(1);

  for (const component of generated.bundle.components) {
    const stock = (await api<{ data: Stock }>(page, "GET", `/api/dashboard/inventory/${component.sku_id}`)).body.data;

    if (stock.available < 300) {
      const adjusted = await api(page, "POST", `/api/dashboard/inventory/${component.sku_id}/adjust`, {
        new_on_hand: stock.reserved + 500,
        reason: "e2e grade set top-up",
      });
      expect(adjusted.status).toBe(200);
    }
  }

  // Review in the editor: a name for this run and 10 % off the sum, with the saving shown live.
  await page.reload();
  await page.getByLabel("اسم الباقة *", { exact: true }).fill(name);
  await page.getByLabel("نسبة الخصم % *", { exact: true }).fill("10");
  await expect(page.getByTestId("bundle-saving")).not.toHaveText("0.00 ج.م");
  const saved = page.waitForResponse(
    (response) => response.url().endsWith(`/api/dashboard/bundles/${id}`) && response.request().method() === "PUT",
  );
  await page.getByRole("button", { name: "حفظ" }).first().click();
  expect((await saved).status()).toBe(200);
  await expect(page.getByRole("heading", { level: 1, name })).toBeVisible();

  await page.getByTestId("bundle-draft").getByRole("button", { name: "تفعيل الباقة" }).click();
  await expect(page.getByTestId("bundle-draft")).toBeHidden();
  await expect(page.getByTestId("bundle-availability")).not.toHaveText("متاح الآن: 0 باقة");

  const bundle = (await api<{ data: Bundle }>(page, "GET", `/api/dashboard/products/${id}`)).body.data;
  expect(bundle.status).toBe("active");
  const quantity = Math.max(bundle.sku.moq, Math.ceil(600 / Number(bundle.sku.base_price)));

  // The products list marks it and opens it in the bundle editor.
  await page.goto(`/dashboard/products?q=${encodeURIComponent(name)}`);
  await expect(page.getByTestId(`bundle-badge-${id}`)).toBeVisible();
  await expect(page.getByRole("link", { name })).toHaveAttribute("href", `/dashboard/bundles/${id}`);

  // A shopper finds it, sees what it holds, and buys it.
  const shopper = await shopperSession(browser);
  const store = shopper.page;
  await store.goto(`/search?q=${encodeURIComponent(name)}`);
  await expect(store.getByTestId("bundle-card").first()).toContainText("وفّر");
  await store.goto(`/p/${encodeURIComponent(bundle.slug)}`);
  await expect(store.getByRole("heading", { level: 1, name })).toBeVisible();
  const contents = store.getByRole("region", { name: "محتويات الباقة" });
  await expect(contents.getByRole("listitem")).toHaveCount(bundle.bundle.components.length);
  await expect(store.getByTestId("bundle-availability")).toBeVisible();

  await store.getByRole("spinbutton", { name: "الكمية" }).fill(String(quantity));
  const added = store.waitForResponse(
    (response) => response.url().endsWith("/api/store/cart/items") && response.request().method() === "POST",
  );
  await store.getByRole("button", { name: "أضف إلى السلة" }).click();
  expect((await added).status()).toBe(200);

  await store.goto("/cart");
  const line = store.getByRole("listitem").filter({ hasText: name });
  await expect(line).toHaveCount(1);
  await expect(line.getByText("باقة", { exact: true })).toBeVisible();

  const addresses = await api<{ data: { id: number }[] }>(store, "GET", "/api/store/addresses");
  const checkout = await api<{ data: { order: { number: number } } }>(
    store,
    "POST",
    "/api/store/checkout",
    { address_id: addresses.body.data[0]?.id, customer_note: null },
    { "Idempotency-Key": `e2e-bundles-${Date.now()}` },
  );
  expect(checkout.status).toBe(201);
  const number = checkout.body.data.order.number;

  // The shopper's order: one bundle line that opens to its components.
  await store.goto(`/account/orders/${number}`);
  await expect(store.getByRole("heading", { level: 1, name: `طلب رقم ${number}` })).toBeVisible();
  const order = (await api<{ data: { items: OrderItem[] } }>(page, "GET", `/api/dashboard/orders/${number}`)).body.data;
  const bundleLine = order.items.find((item) => item.is_bundle);
  expect(bundleLine).toMatchObject({ product_name: name, quantity });
  const children = order.items.filter((item) => item.parent_item_id === bundleLine!.id);
  expect(children).toHaveLength(bundle.bundle.components.length);

  const storeLine = store.getByTestId(`order-line-${bundleLine!.id}`);
  await expect(storeLine).toContainText("باقة");
  await expect(store.getByTestId(`order-line-${children[0]!.id}`)).toHaveCount(0);
  await storeLine.getByRole("button", { name: `عرض المكونات (${children.length})` }).click();
  await expect(store.getByRole("list", { name: `مكونات ${name}` }).getByRole("listitem")).toHaveCount(children.length);
  await shopper.close();

  // The merchant's order page shows the same.
  await page.goto(`/dashboard/orders/${number}`);
  const dashboardLine = page.getByTestId(`order-line-${bundleLine!.id}`);
  await expect(dashboardLine).toContainText(name);
  await dashboardLine.getByRole("button", { name: `عرض المكونات (${children.length})` }).click();
  await expect(page.getByTestId(`bundle-components-${bundleLine!.id}`).getByRole("listitem")).toHaveCount(
    children.length,
  );

  // Leave the catalog as it was: cancel the order (releases the components) and hide the set.
  expect(
    (await api(page, "POST", `/api/dashboard/orders/${number}/cancel`, { reason: "e2e bundle cleanup" })).status,
  ).toBeLessThan(300);
  expect((await api(page, "PATCH", `/api/dashboard/products/${id}/status`, { status: "inactive" })).status).toBe(200);
});
