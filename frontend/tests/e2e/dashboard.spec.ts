import { expect, test } from "@playwright/test";

import { loginAsOwner, placeShopperOrder, uniqueSuffix } from "./helpers";

// The merchant dashboard against the real stack (tasks/10). Needs the seeded backend (php artisan
// migrate:fresh --seed). Every run creates its own names, phones and orders, so runs do not collide, and the
// bulk actions are filtered to this run's product so the seeded catalog the storefront spec uses stays as is.
// Each test is a whole journey against the single-threaded php artisan serve, hence the longer timeout.
test.describe.configure({ timeout: 90_000 });

test("a merchant builds a category, unit, custom field and product, sees it on the store, then reprices it in bulk", async ({
  page,
}) => {
  const run = uniqueSuffix();
  const category = `قسم اختبار ${run}`;
  const unit = `وحدة ${run}`;
  const field = `المؤلف ${run}`;
  const product = `كتاب اختبار ${run}`;
  await loginAsOwner(page);

  // Category (08 §6).
  await page.goto("/dashboard/categories");
  await page.getByRole("button", { name: "إضافة قسم" }).click();
  await page.getByLabel("اسم القسم").fill(category);
  await page.getByRole("button", { name: "حفظ" }).click();
  await expect(page.getByText(category)).toBeVisible();

  // Unit.
  await page.goto("/dashboard/units");
  await page.getByPlaceholder("مثال: كرتونة").fill(unit);
  await page.getByRole("button", { name: "إضافة وحدة" }).click();
  await expect(page.locator(`input[value="${unit}"]`)).toBeVisible();

  // A select field with two options.
  await page.goto("/dashboard/fields");
  await page.getByRole("button", { name: "إضافة حقل" }).click();
  const fieldDialog = page.getByRole("dialog");
  await fieldDialog.getByLabel("الاسم الظاهر").fill(field);
  await fieldDialog.getByLabel("المفتاح (بالإنجليزية)").fill(`author_${run}`);
  await fieldDialog.getByLabel("الاختيارات").fill("أحمد\nمحمد");
  await fieldDialog.getByRole("button", { name: "حفظ" }).click();
  await expect(page.getByTestId(`field-author_${run}`)).toBeVisible();

  // The product with its custom field, price and opening stock.
  await page.goto("/dashboard/products/new");
  await page.getByLabel("اسم المنتج *", { exact: true }).fill(product);
  await page.getByLabel("القسم *", { exact: true }).selectOption({ label: category });
  await page.getByLabel("كود الصنف (SKU)").fill(`E2E-${run}`);
  await page.getByLabel("وحدة البيع *", { exact: true }).selectOption({ label: unit });
  await page.getByLabel("الوحدة الأساسية *", { exact: true }).selectOption({ label: unit });
  await page.getByLabel("الرصيد الافتتاحي (بالوحدة الأساسية)").fill("50");
  await page.getByRole("tab", { name: "الحقول" }).click();
  await page.getByLabel(field).selectOption({ label: "أحمد" });
  await page.getByRole("tab", { name: "التسعير" }).click();
  await page.getByLabel("سعر الغلاف (اختياري)", { exact: true }).fill("100.00");
  await page.getByLabel("سعر الجملة *", { exact: true }).fill("80.00");
  await expect(page.getByTestId("product-base_value-hint")).toHaveText("خصم 20.00% من سعر الغلاف");
  await page.getByRole("button", { name: "حفظ" }).first().click();
  await expect(page).toHaveURL(/\/dashboard\/products\/\d+$/);
  await expect(page.getByRole("heading", { level: 1, name: product })).toBeVisible();

  // It is on the storefront.
  await page.goto(`/search?q=${encodeURIComponent(product)}`);
  await expect(page.getByRole("link", { name: product, exact: true }).first()).toBeVisible();

  // Bulk pricing for exactly this product: preview first, then apply (BR-PR11).
  await page.goto(`/dashboard/products?q=${encodeURIComponent(product)}`);
  await expect(page.getByRole("link", { name: product })).toBeVisible();
  await page.getByRole("button", { name: "تسعير جماعي" }).click();
  const bulk = page.getByRole("dialog");
  await bulk.getByLabel("خصم السعر الأساسي %").fill("30");
  await expect(bulk.getByRole("button", { name: "تطبيق" })).toBeDisabled();
  await bulk.getByRole("button", { name: "معاينة" }).click();
  await expect(bulk.getByTestId("bulk-preview")).toContainText("سيتم تحديث 1 منتج");
  await bulk.getByRole("button", { name: "تطبيق" }).click();
  await expect(page.getByText("تم تحديث أسعار 1 منتج")).toBeVisible();
  await expect(page.getByTestId(/^product-\d+$/).first()).toContainText("70.00 ج.م");
});

test("BR-INV08 a reserved quantity blocks the adjustment, then the order is confirmed, delivered and collected", async ({
  page,
  browser,
}) => {
  const order = await placeShopperOrder(browser, "الأضواء رياضيات ثانية إعدادي");
  await loginAsOwner(page);

  // Setting the stock to zero would eat the order's reservation: refused, with the order linked.
  await page.goto(`/dashboard/inventory?q=${encodeURIComponent(order.sku.code)}`);
  await page.getByTestId(`stock-${order.sku.id}`).getByRole("button", { name: "تعديل الرصيد" }).click();
  await expect(page.getByTestId("min-allowed")).toContainText("أقل كمية ممكنة:");
  await page.getByLabel("الرصيد الجديد (بالوحدة الأساسية)").fill("0");
  await page.getByLabel("سبب التعديل").fill("جرد");
  await page.getByRole("button", { name: "حفظ" }).click();
  const refusal = page.getByTestId("below-reserved");
  await expect(refusal).toContainText("لا يمكن تقليل المخزون إلى 0");
  await refusal.getByRole("link", { name: `#${order.number}` }).click();

  // The order page: confirm, then deliver and collect in one step (BR-O01, BR-C04).
  await expect(page).toHaveURL(new RegExp(`/dashboard/orders/${order.number}$`));
  await expect(page.getByTestId("order-status")).toHaveText("قيد الانتظار");
  await page.getByRole("button", { name: "تأكيد الطلب" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("مؤكد");
  await page.getByRole("button", { name: "تسليم وتحصيل كامل" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("تم التوصيل");
  await expect(page.getByTestId("collection-status")).toHaveText("محصل");
  await expect(page.getByTestId("order-balance")).toHaveText("0.00 ج.م");

  // The collection is in the collections list.
  await page.goto(`/dashboard/collections?order=${order.number}`);
  await expect(page.getByRole("link", { name: `#${order.number}` })).toBeVisible();
});

test("no dashboard screen scrolls sideways, on a phone or a desktop (rules/03 §3)", async ({ page }) => {
  await loginAsOwner(page);
  const screens = [
    "/dashboard",
    "/dashboard/orders",
    "/dashboard/collections",
    "/dashboard/customers",
    "/dashboard/reports",
    "/dashboard/products",
    "/dashboard/products/new",
    "/dashboard/categories",
    "/dashboard/units",
    "/dashboard/fields",
    "/dashboard/inventory",
    "/dashboard/settings",
    "/dashboard/users",
    "/dashboard/audit",
  ];

  for (const path of screens) {
    await page.goto(path);
    await expect(page.getByRole("main").getByRole("heading", { level: 1 })).toBeVisible();
    await expect(page.getByLabel("جاري التحميل…")).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, path).toBeLessThanOrEqual(0);
  }

  // A detail page with a wide items table.
  await page.goto("/dashboard/orders");
  await page
    .getByRole("link", { name: /^#\d+$/ })
    .first()
    .click();
  await expect(page.getByRole("heading", { level: 1, name: /^طلب #\d+$/ })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
});
