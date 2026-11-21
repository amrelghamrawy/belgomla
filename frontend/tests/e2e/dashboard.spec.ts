import { expect, test } from "@playwright/test";

import { api, freshPhone, lastOtp, loginAsOwner, placeShopperOrder, shopperSession, uniqueSuffix } from "./helpers";

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

  // A books category (08 §6, D68): its products call the reference price "سعر الغلاف".
  await page.goto("/dashboard/categories");
  await page.getByRole("button", { name: "إضافة قسم" }).click();
  await page.getByLabel("اسم القسم").fill(category);
  await page.getByLabel("نوع القسم").selectOption("books");
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
  await fieldDialog.getByLabel("اسم الاختيار 1").fill("أحمد");
  await fieldDialog.getByRole("button", { name: "إضافة اختيار" }).click();
  await fieldDialog.getByLabel("اسم الاختيار 2").fill("محمد");
  await fieldDialog.getByRole("button", { name: "حفظ" }).click();
  await expect(page.getByTestId(`field-author_${run}`)).toBeVisible();

  // The product with its custom field, price and opening stock.
  await page.goto("/dashboard/products/new");
  await page.getByLabel("اسم المنتج *", { exact: true }).fill(product);
  await page.getByLabel("القسم *", { exact: true }).click();
  await page.getByPlaceholder("ابحث باسم القسم").fill(category);
  await page.getByRole("option", { name: category }).click();
  await page.getByLabel("كود الصنف (SKU)").fill(`E2E-${run}`);
  await page.getByLabel("الوحدة الأساسية *", { exact: true }).selectOption({ label: unit });
  await page.getByLabel("الرصيد الافتتاحي (بالوحدة الأساسية)").fill("50");
  await page.getByRole("tab", { name: "الحقول" }).click();
  await page.getByLabel(field).selectOption({ label: "أحمد" });
  // D91: the selling units (one here) with their prices sit on the pricing tab.
  await page.getByRole("tab", { name: "التسعير" }).click();
  const firstUnit = page.getByTestId("sku-unit-0");
  await firstUnit.getByLabel("وحدة البيع *", { exact: true }).selectOption({ label: unit });
  await firstUnit.getByLabel("سعر الغلاف (اختياري)", { exact: true }).fill("100.00");
  await firstUnit.getByLabel("سعر الجملة *", { exact: true }).fill("80.00");
  await expect(firstUnit.getByText("خصم 20.00% من سعر الغلاف")).toBeVisible();
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
  // D104: adjusting sits in the row's «⋯» menu, beside the visible «استلام بضاعة».
  await page
    .getByTestId(`stock-${order.sku.id}`)
    .getByRole("button", { name: /إجراءات أخرى/ })
    .click();
  await page.getByRole("menuitem", { name: "تعديل الرصيد" }).click();
  await expect(page.getByTestId("min-allowed")).toContainText("أقل كمية ممكنة:");
  await page.getByLabel("الرصيد الجديد (بالوحدة الأساسية)").fill("0");
  await page.getByLabel("سبب التعديل").selectOption({ label: "جرد" });
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
  await page.getByRole("dialog").getByRole("button", { name: "تسليم وتحصيل" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("تم التوصيل");
  await expect(page.getByTestId("collection-status")).toHaveText("محصل");
  await expect(page.getByTestId("order-balance")).toHaveText("0.00 ج.م");

  // The collection is in the collections list.
  await page.goto(`/dashboard/collections?order=${order.number}`);
  await expect(page.getByRole("link", { name: `#${order.number}` })).toBeVisible();
});

test("BR-N03 the merchant turns on the confirmed message, confirms an order and sees the message on its page", async ({
  page,
  browser,
}) => {
  const order = await placeShopperOrder(browser, "الأضواء رياضيات ثانية إعدادي");
  await loginAsOwner(page);

  try {
    // The demo store notifies nobody (its customers have invented phones), so the merchant turns it on.
    await page.goto("/dashboard/settings");
    const card = page.getByTestId("order-messages");
    const confirmed = card.getByLabel("أرسل رسالة عند: مؤكد");
    await confirmed.check();
    await expect(card.getByTestId("order-message-confirmed-preview")).toContainText("تم تأكيد طلبك رقم 1024");
    await page.getByRole("button", { name: "حفظ" }).filter({ visible: true }).first().click();
    await expect(page.getByText("تم حفظ الإعدادات")).toBeVisible();

    await page.goto(`/dashboard/orders/${order.number}`);
    await expect(page.getByTestId("order-messages")).toHaveCount(0);
    await page.getByRole("button", { name: "تأكيد الطلب" }).click();
    await expect(page.getByTestId("order-status")).toHaveText("مؤكد");
    await expect(page.getByTestId("order-messages")).toContainText("تحديث طلب · واتساب");
  } finally {
    await api(page, "PUT", "/api/dashboard/settings", { order_notify_statuses: [] });
  }
});

test("BR-INV07 D81 the merchant turns on the automatic expiry and a pending order shows its countdown", async ({
  page,
  browser,
}) => {
  const order = await placeShopperOrder(browser, "الأضواء رياضيات ثانية إعدادي");
  await loginAsOwner(page);

  try {
    await page.goto("/dashboard/settings");
    await page.getByLabel("إلغاء الطلبات غير المؤكدة تلقائيًا بعد (ساعة)").fill("48");
    await page.getByRole("button", { name: "حفظ" }).filter({ visible: true }).first().click();
    await expect(page.getByText("تم حفظ الإعدادات")).toBeVisible();

    await page.goto(`/dashboard/orders/${order.number}`);
    await expect(page.getByTestId("expiry")).toContainText(/يُلغى بعد (47|48) س/);
  } finally {
    await api(page, "PUT", "/api/dashboard/settings", { pending_expiry_hours: null });
  }
});

test("BR-T06 D82 the owner adds a sales user, who sees orders but cannot open the settings", async ({
  page,
  browser,
}) => {
  const email = `sales-${uniqueSuffix()}@alnoor.test`;
  await loginAsOwner(page);
  await page.goto("/dashboard/users");
  await page.getByRole("button", { name: "مستخدم جديد" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("الاسم").fill("موظف مبيعات");
  await dialog.getByLabel("البريد الإلكتروني").fill(email);
  await dialog.getByLabel("كلمة المرور").fill("secret123");
  await expect(dialog.getByLabel("الدور")).toHaveValue("sales");
  await dialog.getByRole("button", { name: "حفظ" }).click();
  await expect(page.getByRole("combobox", { name: "دور موظف مبيعات" }).first()).toHaveValue("sales");

  const context = await browser.newContext();
  const sales = await context.newPage();
  await loginAsOwner(sales, email, "secret123");
  await expect(sales.getByTestId("welcome")).toBeVisible();

  await sales.goto("/dashboard/orders");
  await expect(sales.getByRole("heading", { name: "الطلبات" })).toBeVisible();

  await sales.goto("/dashboard/settings");
  await expect(sales.getByTestId("forbidden")).toContainText("ليس لديك صلاحية لفتح هذه الصفحة");
  await context.close();
});

test("BR-T10 D112 a user with a mobile resets a forgotten password with the WhatsApp code", async ({
  page,
  browser,
}) => {
  const email = `reset-${uniqueSuffix()}@alnoor.test`;
  const phone = freshPhone();
  await loginAsOwner(page);
  const created = await api(page, "POST", "/api/dashboard/users", {
    name: "موظف نسي كلمته",
    email,
    phone,
    password: "secret123",
    role: "sales",
  });
  expect(created.status).toBe(201);

  const context = await browser.newContext();
  const guest = await context.newPage();
  await guest.goto("/dashboard/login");
  await guest.getByRole("link", { name: "نسيت كلمة المرور؟" }).click();
  await expect(guest).toHaveURL(/\/dashboard\/forgot-password$/);
  await guest.getByLabel("البريد الإلكتروني").fill(email);
  await guest.getByRole("button", { name: "أرسل الكود" }).click();
  await expect(guest.getByText(`إذا كان ${email} مسجلًا وله رقم موبايل`)).toBeVisible();

  await guest.getByLabel("كود التحقق").fill(await lastOtp(guest, phone));
  await guest.getByLabel("كلمة المرور الجديدة").fill("brand-new-1");
  await guest.getByRole("button", { name: "حفظ كلمة المرور" }).click();
  await expect(guest).toHaveURL(/\/dashboard\/login$/);

  await loginAsOwner(guest, email, "brand-new-1");
  await expect(guest.getByTestId("welcome")).toBeVisible();
  await context.close();
});

test("BR-R01 BR-R03 a delivered order takes a return of 2: the stock comes back and the balance drops", async ({
  page,
  browser,
}) => {
  const order = await placeShopperOrder(browser, "الأضواء رياضيات ثانية إعدادي");
  await loginAsOwner(page);
  const stock = async () =>
    (
      await api<{ data: { on_hand: number; conversion_factor: number } }>(
        page,
        "GET",
        `/api/dashboard/inventory/${order.sku.id}`,
      )
    ).body.data;

  await page.goto(`/dashboard/orders/${order.number}`);
  await page.getByRole("button", { name: "تأكيد الطلب" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("مؤكد");
  await page.getByRole("button", { name: "تم التسليم", exact: true }).click();
  await page.getByRole("dialog").getByRole("button", { name: "تأكيد التسليم" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("تم التوصيل");
  const balanceBefore = await page.getByTestId("order-balance").textContent();
  const before = await stock();

  await page.getByRole("button", { name: "تسجيل مرتجع" }).click();
  const dialog = page.getByRole("dialog");
  const plus = dialog.getByRole("button", { name: /^الكمية المرتجعة \+/ });
  await plus.click();
  await plus.click();
  await dialog.getByLabel("سبب المرتجع").fill("مرتجع نهاية الموسم");
  await dialog.getByRole("button", { name: "تسجيل مرتجع" }).click();

  await expect(page.getByTestId("order-returns")).toContainText("مرتجع #1");
  await expect(page.getByTestId("order-returned")).toBeVisible();
  await expect(page.getByTestId("order-balance")).not.toHaveText(balanceBefore ?? "");
  expect((await stock()).on_hand).toBe(before.on_hand + 2 * before.conversion_factor);

  await page.goto("/dashboard/returns");
  await expect(page.getByRole("link", { name: new RegExp(`#${order.number}`) })).toBeVisible();
});

test("no dashboard screen scrolls sideways, on a phone or a desktop (rules/03 §3)", async ({ page }) => {
  await loginAsOwner(page);
  const screens = [
    "/dashboard",
    "/dashboard/orders",
    "/dashboard/orders/new",
    "/dashboard/collections",
    "/dashboard/returns",
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

  // D104: a detail page with a bar of tabs wider than a phone; the bar scrolls, the page does not.
  await page.goto("/dashboard/customers");
  await page.getByTestId(/^customer-\d+$/).first().getByRole("link").first().click();
  await expect(page.getByRole("tab").first()).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
});

test("BR-C05 a credit limit blocks the next checkout until the merchant collects", async ({ page, browser }) => {
  const shopper = await shopperSession(browser);
  const first = await shopper.order("الأضواء رياضيات ثانية إعدادي");
  expect(first.checkout.status).toBe(201);
  const number = first.checkout.body.data?.order.number ?? 0;
  await loginAsOwner(page);

  // Deliver the first order unpaid, then cap the customer at what they already owe.
  await api(
    page,
    "POST",
    `/api/dashboard/orders/${number}/transition`,
    { to: "delivered" },
    { "Idempotency-Key": `e2e-d-${number}` },
  );
  const detail = await api<{ data: { net_total: string; customer: { id: number } } }>(
    page,
    "GET",
    `/api/dashboard/orders/${number}`,
  );
  const customerId = detail.body.data.customer.id;
  await page.goto(`/dashboard/customers/${customerId}`);
  await page.getByLabel("حد الائتمان").fill(detail.body.data.net_total);
  await page.getByRole("button", { name: "حفظ البيانات" }).click();
  await expect(page.getByText("تم حفظ بيانات العميل")).toBeVisible();

  const refused = await shopper.order("الأضواء رياضيات ثانية إعدادي");
  expect(refused.checkout.status).toBe(422);
  expect(refused.checkout.body.error?.details?.credit).toMatchObject({
    limit: detail.body.data.net_total,
    exposure: detail.body.data.net_total,
  });

  // The statement shows the debt; collecting it frees the limit.
  await page.goto(`/dashboard/customers/${customerId}?tab=statement`);
  await expect(page.getByTestId("ledger")).toContainText(`طلب #${number}`);
  await page.goto(`/dashboard/orders/${number}`);
  await page.getByRole("button", { name: "تسجيل تحصيل" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "تسجيل تحصيل" }).click();
  await expect(page.getByTestId("order-balance")).toHaveText("0.00 ج.م");

  const placed = await api(
    shopper.page,
    "POST",
    "/api/store/checkout",
    {
      address_id: (await api<{ data: { id: number }[] }>(shopper.page, "GET", "/api/store/addresses")).body.data[0]?.id,
      customer_note: null,
    },
    { "Idempotency-Key": `e2e-retry-${Date.now()}` },
  );
  expect(placed.status).toBe(201);
  await shopper.close();
});
