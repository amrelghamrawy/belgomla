import { expect, test } from "@playwright/test";

import { api, loginAsOwner, shopperSession, uniqueSuffix } from "./helpers";

// V2 Task 12 (BR-PR15, D90): the merchant filters the products list down to one book and runs a 20% promotion on it
// from «عرض ترويجي», starting an hour ago (Cairo time) and ending in two days; a shopper sees the offer badge on the
// storefront and checking out snapshots the promotion price. The promotion is cancelled at the end, so the price returns.

const BOOK = "المعلم علوم ثانية إعدادي";

type Product = { slug: string; sku: { base_price: string; promotion?: { name: string } } };
type Detail = { items: { unit_price: string; price_source: string }[] };

/** "YYYY-MM-DDTHH:mm" in Cairo, `hours` from now: what a datetime-local input takes. */
function cairoLocal(hours: number): string {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat("en-GB", {
      timeZone: "Africa/Cairo",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
    })
      .formatToParts(new Date(Date.now() + hours * 3_600_000))
      .map((part) => [part.type, part.value]),
  );

  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

test("BR-PR15 a promotion made from the products filter shows on the storefront and is charged at checkout", async ({
  page,
  browser,
}) => {
  const shopper = await shopperSession(browser);
  const search = `/api/store/products?q=${encodeURIComponent(BOOK)}`;
  const before = await api<{ data: Product[] }>(shopper.page, "GET", search);
  const product = before.body.data[0];
  expect(product?.sku.promotion).toBeUndefined();
  const promoted = ((Number(product?.sku.base_price) * 80) / 100).toFixed(2);
  const name = `عرض اختبار ${uniqueSuffix()}`;

  await loginAsOwner(page);
  await page.goto(`/dashboard/products?q=${encodeURIComponent(BOOK)}`);
  await page.getByRole("button", { name: "عرض ترويجي" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("اسم العرض").fill(name);
  await dialog.getByLabel("نسبة الخصم من السعر الأساسي (%)").fill("20");
  await dialog.getByLabel("يبدأ (بتوقيت القاهرة)").fill(cairoLocal(-1));
  await dialog.getByLabel("ينتهي (بتوقيت القاهرة)").fill(cairoLocal(48));
  await dialog.getByRole("button", { name: "معاينة" }).click();
  await expect(dialog.getByTestId("promotion-preview")).toContainText("سيشمل العرض 1 صنف");
  await dialog.getByRole("button", { name: "حفظ العرض" }).click();
  await expect(dialog).toBeHidden();

  await page.goto("/dashboard/promotions");
  const row = page.getByRole("row", { name: new RegExp(name) });
  await expect(row).toContainText("جارٍ");
  await expect(row).toContainText("20%");
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);

  await shopper.page.goto(`/p/${encodeURIComponent(product?.slug ?? "")}`);
  await expect(shopper.page.getByTestId("offer-badge").first()).toContainText("عرض 20%");
  await expect(shopper.page.getByTestId("offer-badge").first()).toContainText("ينتهي خلال");
  const after = await api<{ data: Product[] }>(shopper.page, "GET", search);
  expect(after.body.data[0]?.sku).toMatchObject({ base_price: promoted, promotion: { name } });

  const { checkout } = await shopper.order(BOOK);
  expect(checkout.status).toBe(201);
  const mine = await api<{ data: Detail }>(
    shopper.page,
    "GET",
    `/api/store/orders/${checkout.body.data?.order.number}`,
  );
  expect(mine.body.data.items[0]).toMatchObject({ unit_price: promoted, price_source: "promotion" });

  await row.getByRole("button", { name: "إلغاء العرض" }).click();
  await page.getByRole("dialog").getByRole("button", { name: "إلغاء العرض" }).click();
  await expect(row).toContainText("ملغي");
  const restored = await api<{ data: Product[] }>(shopper.page, "GET", search);
  expect(restored.body.data[0]?.sku.base_price).toBe(product?.sku.base_price);
  await shopper.close();
});
