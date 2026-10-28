import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession, uniqueSuffix } from "./helpers";

// V2 Task 14 (BR-P14, D92): a pen with one option «اللون» in three colours, each colour its own SKU with its own code,
// price and stock. The merchant creates it through the dashboard API (blue 50.00 × 40, black 55.00 × 40, red 50.00
// with no stock); a shopper opens the product page, sees red disabled, adds 10 blue, picks black (the URL keeps
// ?sku=), adds 10 black and checks out. The order has a line per colour labelled with it, and each colour's stock holds
// its own 10. The product is made in the run, so the seeded catalog stays as it is.

type Unit = { id: number; name: string; status: string };
type Category = { id: number; status: string; children?: Category[] };
type Sku = { id: number; code: string; option_values: string[] };
type Product = { id: number; slug: string; skus: Sku[] };
type OrderItem = { sku_id: number; sku_name: string; quantity: number; unit_price: string };

test("BR-P14 a shopper buys two colours of one pen", async ({ page, browser }) => {
  const suffix = uniqueSuffix();
  const name = `قلم ألوان ${suffix}`;
  await loginAsOwner(page);

  const units = (await api<{ data: Unit[] }>(page, "GET", "/api/dashboard/units")).body.data;
  let piece = units.find((unit) => unit.name === "قطعة" && unit.status === "active")?.id;

  if (piece === undefined) {
    const created = await api<{ data: Unit }>(page, "POST", "/api/dashboard/units", { name: `قطعة ${suffix}` });
    expect(created.status).toBe(201);
    piece = created.body.data.id;
  }

  const categories = (await api<{ data: Category[] }>(page, "GET", "/api/dashboard/categories")).body.data;
  const category = categories.find((root) => root.status === "active");
  expect(category).toBeDefined();

  const variant = (colour: string, position: number, price: string, stock: number) => ({
    code: `PEN-${suffix}-${position}`,
    base_unit_id: piece,
    status: "active",
    option_values: [colour],
    opening_stock: stock,
    units: [{ unit_id: piece, conversion_factor: 1, base_price: price, moq: 1, tiers: [], is_default: true }],
  });
  const created = await api<{ data: Product }>(page, "POST", "/api/dashboard/products", {
    name,
    category_id: category!.id,
    status: "active",
    options: [{ name: "اللون", values: [{ value: "أزرق" }, { value: "أسود" }, { value: "أحمر" }] }],
    skus: [variant("أزرق", 1, "50.00", 40), variant("أسود", 2, "55.00", 40), variant("أحمر", 3, "50.00", 0)],
  });
  expect(created.status).toBe(201);
  const product = created.body.data;
  const skuOf = (colour: string) => product.skus.find((sku) => sku.option_values[0] === colour)!;
  expect(product.skus).toHaveLength(3);

  const shopper = await shopperSession(browser);
  const store = shopper.page;
  await store.goto(`/p/${encodeURIComponent(product.slug)}`);
  const colours = store.getByRole("radiogroup", { name: "اللون" });
  const addTen = async () => {
    await store.getByRole("spinbutton", { name: "الكمية" }).fill("10");
    const added = store.waitForResponse(
      (response) => response.url().endsWith("/api/store/cart/items") && response.request().method() === "POST",
    );
    await store.getByRole("button", { name: "أضف إلى السلة" }).click();
    expect((await added).status()).toBe(200);
  };

  await expect(colours).toBeVisible();
  await expect(colours.getByRole("radio", { name: /أحمر/ })).toBeDisabled();

  await colours.getByRole("radio", { name: "أزرق" }).click();
  await expect(colours.getByRole("radio", { name: "أزرق" })).toHaveAttribute("aria-checked", "true");
  await expect(store.getByText(`PEN-${suffix}-1`)).toBeVisible();
  await addTen();

  await colours.getByRole("radio", { name: "أسود" }).click();
  await expect(store).toHaveURL(new RegExp(`\\?sku=${skuOf("أسود").id}$`));
  await expect(colours.getByRole("radio", { name: "أسود" })).toHaveAttribute("aria-checked", "true");
  await expect(store.getByText("55.00 ج.م").first()).toBeVisible();
  await expect(store.getByText(`PEN-${suffix}-2`)).toBeVisible();
  await addTen();

  await store.goto("/cart");
  const lines = store.getByRole("listitem").filter({ hasText: name });
  await expect(lines).toHaveCount(2);
  await expect(lines.filter({ hasText: "500.00" })).toHaveCount(1);
  await expect(lines.filter({ hasText: "550.00" })).toHaveCount(1);
  await expect(lines.filter({ hasText: "أزرق" })).toHaveCount(1);
  await expect(lines.filter({ hasText: "أسود" })).toHaveCount(1);

  const addresses = await api<{ data: { id: number }[] }>(store, "GET", "/api/store/addresses");
  const checkout = await api<{ data: { order: { number: number } } }>(
    store,
    "POST",
    "/api/store/checkout",
    { address_id: addresses.body.data[0]?.id, customer_note: null },
    { "Idempotency-Key": `e2e-variants-${Date.now()}` },
  );
  expect(checkout.status).toBe(201);
  const number = checkout.body.data.order.number;

  // The shopper's order names the colour of each line.
  await store.goto(`/account/orders/${number}`);
  await expect(store.getByRole("heading", { level: 1, name: `طلب رقم ${number}` })).toBeVisible();
  await expect(store.getByText("أزرق", { exact: true })).toBeVisible();
  await expect(store.getByText("أسود", { exact: true })).toBeVisible();
  await shopper.close();

  const order = await api<{ data: { items: OrderItem[] } }>(page, "GET", `/api/dashboard/orders/${number}`);
  const items = order.body.data.items;
  expect(items).toHaveLength(2);
  expect(items.find((item) => item.sku_id === skuOf("أزرق").id)).toMatchObject({
    sku_name: "أزرق",
    quantity: 10,
    unit_price: "50.00",
  });
  expect(items.find((item) => item.sku_id === skuOf("أسود").id)).toMatchObject({
    sku_name: "أسود",
    quantity: 10,
    unit_price: "55.00",
  });

  for (const colour of ["أزرق", "أسود"]) {
    const stock = await api<{ data: { reserved: number; available: number } }>(
      page,
      "GET",
      `/api/dashboard/inventory/${skuOf(colour).id}`,
    );
    expect(stock.body.data).toMatchObject({ reserved: 10, available: 30 });
  }
});
