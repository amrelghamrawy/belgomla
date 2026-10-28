import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession, uniqueSuffix } from "./helpers";

// V2 Task 13 (D91): one SKU sold by the piece and by the carton of 12, each at its own price, drawing on one stock in
// pieces. The merchant creates it through the dashboard API with 100 pieces; a shopper picks the carton on the product
// page and adds 2, picks the piece and adds 5, sees two cart lines and checks out; the order has a line per unit and the
// stock holds 2 × 12 + 5 = 29 pieces for it. The product is made in the run, so the seeded catalog stays as it is.

type Unit = { id: number; name: string; status: string };
type Category = { id: number; status: string; children?: Category[] };
type Product = { id: number; slug: string; sku: { id: number } };
type OrderItem = { unit: string; quantity: number; conversion_factor: number; unit_price: string };

test("D91 a shopper buys 2 cartons and 5 pieces of one SKU", async ({ page, browser }) => {
  const suffix = uniqueSuffix();
  await loginAsOwner(page);

  const units = (await api<{ data: Unit[] }>(page, "GET", "/api/dashboard/units")).body.data;
  const unitNamed = async (name: string): Promise<number> => {
    const found = units.find((unit) => unit.name === name && unit.status === "active");

    if (found !== undefined) {
      return found.id;
    }

    const created = await api<{ data: Unit }>(page, "POST", "/api/dashboard/units", { name: `${name} ${suffix}` });
    expect(created.status).toBe(201);

    return created.body.data.id;
  };
  const piece = await unitNamed("قطعة");
  const carton = await unitNamed("كرتونة");
  const categories = (await api<{ data: Category[] }>(page, "GET", "/api/dashboard/categories")).body.data;
  const category = categories.find((root) => root.status === "active");
  expect(category).toBeDefined();

  const created = await api<{ data: Product }>(page, "POST", "/api/dashboard/products", {
    name: `كراسة وحدات ${suffix}`,
    category_id: category!.id,
    status: "active",
    opening_stock: 100,
    sku: {
      code: `MU-${suffix}`,
      base_unit_id: piece,
      units: [
        { unit_id: piece, conversion_factor: 1, base_price: "25.00", moq: 1, tiers: [], is_default: true },
        { unit_id: carton, conversion_factor: 12, base_price: "240.00", moq: 1, tiers: [] },
      ],
    },
  });
  expect(created.status).toBe(201);
  const product = created.body.data;

  const shopper = await shopperSession(browser);
  const store = shopper.page;
  await store.goto(`/p/${encodeURIComponent(product.slug)}`);
  const picker = store.getByRole("radiogroup", { name: "اختر وحدة البيع" });
  const addToCart = async () => {
    const added = store.waitForResponse(
      (response) => response.url().endsWith("/api/store/cart/items") && response.request().method() === "POST",
    );
    await store.getByRole("button", { name: "أضف إلى السلة" }).click();
    expect((await added).status()).toBe(200);
  };
  await expect(picker).toBeVisible();

  await picker.getByRole("radio", { name: /\(12\)/ }).click();
  await expect(store.getByText("240.00 ج.م").first()).toBeVisible();
  await store.getByRole("button", { name: "زيادة الكمية" }).click();
  await expect(store.getByRole("spinbutton", { name: "الكمية" })).toHaveValue("2");
  await addToCart();

  await picker.getByRole("radio").first().click();
  await expect(store.getByText("25.00 ج.م").first()).toBeVisible();
  await store.getByRole("spinbutton", { name: "الكمية" }).fill("5");
  await addToCart();

  await store.goto("/cart");
  const lines = store.getByRole("listitem").filter({ hasText: `كراسة وحدات ${suffix}` });
  await expect(lines).toHaveCount(2);
  await expect(lines.filter({ hasText: "(12)" })).toContainText("480.00");
  await expect(lines.filter({ hasNotText: "(12)" })).toContainText("125.00");

  const addresses = await api<{ data: { id: number }[] }>(store, "GET", "/api/store/addresses");
  const checkout = await api<{ data: { order: { number: number } } }>(
    store,
    "POST",
    "/api/store/checkout",
    { address_id: addresses.body.data[0]?.id, customer_note: null },
    { "Idempotency-Key": `e2e-units-${Date.now()}` },
  );
  expect(checkout.status).toBe(201);
  await shopper.close();

  const order = await api<{ data: { items: OrderItem[] } }>(
    page,
    "GET",
    `/api/dashboard/orders/${checkout.body.data.order.number}`,
  );
  const items = order.body.data.items.map((item) => ({ base: item.quantity * item.conversion_factor, ...item }));
  expect(items).toHaveLength(2);
  expect(items.map((item) => item.base).sort((a, b) => a - b)).toEqual([5, 24]);
  expect(items.find((item) => item.conversion_factor === 12)).toMatchObject({ quantity: 2, unit_price: "240.00" });
  expect(items.find((item) => item.conversion_factor === 1)).toMatchObject({ quantity: 5, unit_price: "25.00" });

  const stock = await api<{ data: { reserved: number; available: number } }>(
    page,
    "GET",
    `/api/dashboard/inventory/${product.sku.id}`,
  );
  expect(stock.body.data).toMatchObject({ reserved: 29, available: 71 });
});
