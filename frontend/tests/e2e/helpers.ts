import { type Browser, expect, type Page, test } from "@playwright/test";

// Shared by the e2e specs. Calls a test makes itself go through the page (page.evaluate + fetch): only
// Chromium maps *.gomal.test to 127.0.0.1, Node's page.request does not (rules/03 §6).

export function freshPhone(): string {
  return `0101${String(Date.now()).slice(-7)}`;
}

export function uniqueSuffix(): string {
  return String(Date.now()).slice(-6);
}

/** The newest OTP sent to a phone (D62, local only). */
export async function lastOtp(page: Page, phone: string): Promise<string> {
  const body = await page.evaluate(async (to) => {
    const response = await fetch(`/api/_test/last-otp?phone=${to}`, { headers: { Accept: "application/json" } });

    return (await response.json()) as { data?: { code: string } };
  }, phone);

  expect(body.data?.code).toMatch(/^\d{6}$/);

  return body.data?.code ?? "";
}

/** A JSON call from inside the page with the Sanctum CSRF dance; returns the status and the parsed body. */
export async function api<T = unknown>(
  page: Page,
  method: string,
  path: string,
  body?: unknown,
  headers: Record<string, string> = {},
): Promise<{ status: number; body: T }> {
  return page.evaluate(
    async ({ method, path, body, headers }) => {
      const token = () => {
        const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

        return match?.[1] === undefined ? null : decodeURIComponent(match[1]);
      };

      if (token() === null) {
        await fetch("/sanctum/csrf-cookie", { credentials: "include" });
      }

      const response = await fetch(path, {
        method,
        credentials: "include",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-XSRF-TOKEN": token() ?? "",
          ...headers,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      });

      return { status: response.status, body: response.status === 204 ? null : await response.json() };
    },
    { method, path, body, headers },
  ) as Promise<{ status: number; body: T }>;
}

export async function loginAsOwner(page: Page): Promise<void> {
  await page.goto("/dashboard/login");
  await page.getByLabel("البريد الإلكتروني").fill("owner@alnoor.test");
  await page.getByLabel("كلمة المرور").fill("password");
  await page.getByRole("button", { name: "دخول" }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
}

type StoreProduct = { name: string; sku: { id: number; code: string; base_price: string; moq: number } };

/**
 * A shopper in their own browser context registers, verifies the phone and places an order for the first
 * product matching `search`, enough units to pass the minimum order value. Returns the order number and SKU.
 */
export async function placeShopperOrder(
  browser: Browser,
  search: string,
): Promise<{ number: number; sku: StoreProduct["sku"] }> {
  const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, locale: "ar-EG" });
  const page = await context.newPage();
  const phone = freshPhone();

  await page.goto("/");
  expect(
    (await api(page, "POST", "/api/store/auth/register", { name: "مكتبة اختبار اللوحة", phone, password: "secret123" }))
      .status,
  ).toBeLessThan(300);
  const verified = await api(page, "POST", "/api/store/auth/verify-otp", {
    phone,
    code: await lastOtp(page, phone),
    purpose: "register",
  });
  expect(verified.status).toBeLessThan(300);

  const products = await api<{ data: StoreProduct[] }>(
    page,
    "GET",
    `/api/store/products?q=${encodeURIComponent(search)}`,
  );
  const product = products.body.data[0];
  expect(product).toBeDefined();
  const sku = product!.sku;
  const quantity = Math.max(sku.moq, Math.ceil(600 / Number(sku.base_price)));

  expect((await api(page, "POST", "/api/store/cart/items", { sku_id: sku.id, quantity })).status).toBeLessThan(300);
  const address = await api<{ data: { id: number } }>(page, "POST", "/api/store/addresses", {
    label: "المكتبة",
    governorate: "القاهرة",
    city: "مدينة نصر",
    address_line: "12 شارع عباس العقاد",
  });
  const placed = await api<{ data: { order: { number: number } } }>(
    page,
    "POST",
    "/api/store/checkout",
    { address_id: address.body.data.id, customer_note: null },
    { "Idempotency-Key": `e2e-${Date.now()}` },
  );
  expect(placed.status).toBe(201);
  await context.close();

  return { number: placed.body.data.order.number, sku };
}
