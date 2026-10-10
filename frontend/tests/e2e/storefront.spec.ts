import { expect, test, type Page } from "@playwright/test";

// The full shopper journey against the real stack (tasks/9): register → OTP → browse → facet → product → cart →
// checkout → order → cancel. Needs the backend seeded with StorefrontDemoSeeder (php artisan migrate:fresh --seed)
// and the local-only GET /api/_test/last-otp (D62). Each run uses a new phone: the OTP limit is 5 per phone per hour.

function freshPhone(): string {
  return `0101${String(Date.now()).slice(-7)}`;
}

/** Asked from inside the page: only Chromium maps *.gomal.test to 127.0.0.1 (playwright.config.ts). */
async function lastOtp(page: Page, phone: string): Promise<string> {
  const body = await page.evaluate(async (to) => {
    const response = await fetch(`/api/_test/last-otp?phone=${to}`, { headers: { Accept: "application/json" } });

    return (await response.json()) as { data?: { code: string } };
  }, phone);

  expect(body.data?.code).toMatch(/^\d{6}$/);

  return body.data?.code ?? "";
}

test("a new shopper registers, orders a book and cancels the pending order", async ({ page }) => {
  const phone = freshPhone();

  // Register and verify the phone (BR-CU04, BR-CU07).
  await page.goto("/account/register");
  await page.getByLabel("الاسم أو اسم المكتبة").fill("مكتبة الاختبار");
  await page.getByLabel("رقم الهاتف").fill(phone);
  await page.getByLabel("كلمة المرور").fill("secret123");
  await page.getByRole("button", { name: "إنشاء حساب" }).click();

  await expect(page).toHaveURL(/\/account\/verify\?/);
  await expect(page.getByRole("button", { name: /إعادة الإرسال بعد/ })).toBeDisabled();
  await page.getByLabel("كود التحقق").fill(await lastOtp(page, phone));
  await page.getByRole("button", { name: "تأكيد" }).click();
  await expect(page).toHaveURL(/\/$/);

  // Browse a category and filter it by grade (BR-P08: the selection is in the URL).
  await page.getByRole("link", { name: "المرحلة الإعدادية" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "المرحلة الإعدادية" })).toBeVisible();
  await page.getByRole("button", { name: "تصفية" }).click();
  await page.getByRole("radio", { name: /^أولى إعدادي/ }).click();
  await expect(page).toHaveURL(/f%5Bgrade%5D=\d+|f\[grade\]=\d+/);
  await page.getByRole("button", { name: /عرض \d+ منتج/ }).click();

  // The product page: 6 copies at 88.00 = 528.00, above the 500.00 minimum order (BR-MOV).
  await page.getByRole("link", { name: "الأضواء عربي أولى إعدادي", exact: true }).first().click();
  await expect(page.getByRole("heading", { level: 1, name: "الأضواء عربي أولى إعدادي" })).toBeVisible();
  await expect(page.getByText("نسخ أخرى من هذا الكتاب")).toBeVisible();
  const quantity = page.getByLabel("الكمية", { exact: true });
  await quantity.fill("6");
  await quantity.press("Enter");
  await page.getByRole("button", { name: "أضف إلى السلة" }).click();
  await expect(page.getByText("تمت الإضافة إلى السلة")).toBeVisible();

  await page.goto("/cart");
  await expect(page.getByTestId("cart-total")).toHaveText("528.00 ج.م");
  await expect(page.getByTestId("mov-progress")).toContainText("وصلت للحد الأدنى للطلب");
  await page.getByRole("link", { name: "إتمام الطلب" }).click();

  // Checkout with a new address (BR-CU03, BR-PR06).
  await expect(page).toHaveURL(/\/checkout$/);
  await page.getByRole("button", { name: "إضافة عنوان جديد" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("اسم العنوان").fill("المكتبة");
  await dialog.getByLabel("المحافظة").fill("القاهرة");
  await dialog.getByLabel("المدينة / المنطقة").fill("مدينة نصر");
  await dialog.getByLabel("العنوان بالتفصيل").fill("12 شارع عباس العقاد");
  await dialog.getByRole("button", { name: "حفظ" }).click();
  await expect(page.getByRole("radio", { name: /المكتبة/ })).toBeChecked();
  await page.getByRole("button", { name: "تأكيد الطلب" }).click();

  await expect(page).toHaveURL(/\/checkout\/success\?order=\d+$/);
  await expect(page.getByTestId("order-number")).toBeVisible();
  await page.getByRole("link", { name: "عرض الطلب" }).click();

  // The order page, then cancel it while pending (BR-O02).
  await expect(page.getByTestId("order-status")).toHaveText("قيد الانتظار");
  await expect(page.getByText("الأضواء عربي أولى إعدادي")).toBeVisible();
  await page.getByRole("button", { name: "إلغاء الطلب" }).click();
  await page.getByLabel("سبب الإلغاء").fill("طلبت بالخطأ");
  await page.getByRole("button", { name: "تأكيد الإلغاء" }).click();
  await expect(page.getByTestId("order-status")).toHaveText("ملغي");
  await expect(page.getByRole("button", { name: "إلغاء الطلب" })).toHaveCount(0);

  await page.goto("/account/orders");
  await expect(page.getByRole("link", { name: /طلب رقم \d+/ }).first()).toContainText("ملغي");
});

test("a guest sees prices but is sent to log in before adding to the cart", async ({ page }) => {
  await page.goto("/search?q=الأضواء علوم");

  await expect(page.getByRole("heading", { level: 1 })).toContainText("الأضواء علوم");
  await page.getByRole("link", { name: "الأضواء علوم أولى إعدادي", exact: true }).click();
  await expect(page.getByText("سعر الجملة")).toBeVisible();
  await page.getByRole("button", { name: "أضف إلى السلة" }).click();

  await expect(page).toHaveURL(/\/account\/login\?next=%2Fp%2F/);
});
