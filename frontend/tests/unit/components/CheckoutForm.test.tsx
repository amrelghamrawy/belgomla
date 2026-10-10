import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it } from "vitest";

import { CheckoutForm } from "@/components/store/CheckoutForm";

import { address, cart, cartItem, customer, order } from "../fixtures";
import { callsTo, json, stubApi } from "../http";
import { renderWithQuery, router } from "../render";

beforeEach(() => {
  router.replace.mockReset();
});

function failure(items: Record<string, unknown>[], details?: Record<string, unknown>) {
  return json(422, {
    error: { code: "checkout_failed", message: "تعذر إتمام الطلب", items, ...(details ? { details } : {}) },
  });
}

function stubCheckout(
  routes: Parameters<typeof stubApi>[0] = {},
  addresses = [address(), address({ id: 32, label: "المخزن", is_default: false })],
) {
  return stubApi({
    "GET /api/store/me": json(200, { data: customer() }),
    "GET /api/store/cart": json(200, { data: cart() }),
    "GET /api/store/addresses": json(200, { data: addresses }),
    ...routes,
  });
}

async function placeOrder() {
  const user = userEvent.setup();
  await screen.findByRole("radio", { name: /المكتبة/ });
  await user.click(screen.getByRole("button", { name: "تأكيد الطلب" }));

  return user;
}

describe("CheckoutForm", () => {
  it("BR-CU03 preselects the default address and places the order to the chosen one with the note", async () => {
    const fetchMock = stubCheckout({ "POST /api/store/checkout": json(201, { data: { order: order() } }) });
    const user = userEvent.setup();
    renderWithQuery(<CheckoutForm />);

    expect(await screen.findByRole("radio", { name: /المكتبة/ })).toBeChecked();
    await user.click(screen.getByRole("radio", { name: /المخزن/ }));
    await user.type(screen.getByLabelText("ملاحظات للمتجر (اختياري)"), "التسليم صباحًا");
    await user.click(screen.getByRole("button", { name: "تأكيد الطلب" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/checkout/success?order=1001"));
    const [call] = callsTo(fetchMock, "POST /api/store/checkout");
    expect(call?.body).toEqual({ address_id: 32, customer_note: "التسليم صباحًا" });
    expect(call?.headers["Idempotency-Key"]).toMatch(/^checkout-/);
  });

  it("BR-PR06 lists each refused line with its reason and a link back to the product", async () => {
    stubCheckout({
      "GET /api/store/cart": json(200, {
        data: cart({
          items: [
            cartItem(),
            cartItem({ sku_id: 502, product_name: "المعلم رياضيات", product_slug: "math", unit: "نسخة" }),
          ],
        }),
      }),
      "POST /api/store/checkout": failure([
        { sku_id: 501, reason: "insufficient_stock", requested: 6, available: 3 },
        { sku_id: 502, reason: "below_moq", moq: 10 },
      ]),
    });
    renderWithQuery(<CheckoutForm />);

    await placeOrder();

    const problems = await screen.findByRole("alert");
    expect(problems).toHaveTextContent("راجع هذه الأصناف قبل إتمام الطلب:");
    expect(problems).toHaveTextContent("الأضواء علوم أولى إعدادي");
    expect(problems).toHaveTextContent("المتاح 3 كرتونة فقط");
    expect(problems).toHaveTextContent("الحد الأدنى للطلب 10 نسخة");
    expect(within(problems).getAllByRole("link", { name: "عرض المنتج" })[1]).toHaveAttribute("href", "/p/math");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("BR-P04 explains a product that became inactive", async () => {
    stubCheckout({ "POST /api/store/checkout": failure([{ sku_id: 501, reason: "product_inactive" }]) });
    renderWithQuery(<CheckoutForm />);

    await placeOrder();

    expect(await screen.findByRole("alert")).toHaveTextContent("لم يعد متاحًا للطلب");
  });

  it("BR-MOV01 shows how far the cart is from the minimum order value", async () => {
    stubCheckout({ "POST /api/store/checkout": failure([], { mov: { required: "1000.00", current: "700.00" } }) });
    renderWithQuery(<CheckoutForm />);

    await placeOrder();

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "الحد الأدنى للطلب 1,000.00 ج.م، وإجمالي سلتك 700.00 ج.م",
    );
  });

  it("D57 an emptied cart sends the shopper back to the cart", async () => {
    stubCheckout({ "POST /api/store/checkout": json(422, { error: { code: "cart_empty", message: "السلة فارغة" } }) });
    renderWithQuery(<CheckoutForm />);

    await placeOrder();

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/cart"));
  });

  it("shows any other error's message", async () => {
    stubCheckout({
      "POST /api/store/checkout": json(409, {
        error: { code: "idempotency_in_progress", message: "الطلب قيد التنفيذ، انتظر لحظة" },
      }),
    });
    renderWithQuery(<CheckoutForm />);

    await placeOrder();

    expect(await screen.findByRole("alert")).toHaveTextContent("الطلب قيد التنفيذ، انتظر لحظة");
  });

  it("asks for an address when the customer has none, and selects a new one", async () => {
    const fetchMock = stubCheckout(
      {
        "POST /api/store/addresses": json(201, { data: address({ id: 40, label: "الفرع" }) }),
        "GET /api/store/addresses": [
          json(200, { data: [] }),
          json(200, { data: [address({ id: 40, label: "الفرع" })] }),
        ],
      },
      [],
    );
    const user = userEvent.setup();
    renderWithQuery(<CheckoutForm />);

    await user.click(await screen.findByRole("button", { name: "تأكيد الطلب" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("أضف عنوانًا للتوصيل أولًا");
    expect(callsTo(fetchMock, "POST /api/store/checkout")).toHaveLength(0);

    await user.click(screen.getByRole("button", { name: "إضافة عنوان جديد" }));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("اسم العنوان"), "الفرع");
    await user.type(within(dialog).getByLabelText("المحافظة"), "الجيزة");
    await user.type(within(dialog).getByLabelText("المدينة / المنطقة"), "الدقي");
    await user.type(within(dialog).getByLabelText("العنوان بالتفصيل"), "5 شارع التحرير");
    await user.click(within(dialog).getByRole("button", { name: "حفظ" }));

    expect(await screen.findByRole("radio", { name: /الفرع/ })).toBeChecked();
    expect(callsTo(fetchMock, "POST /api/store/addresses")[0]?.body).toEqual({
      label: "الفرع",
      governorate: "الجيزة",
      city: "الدقي",
      address_line: "5 شارع التحرير",
      landmark: null,
      phone: null,
      is_default: true,
    });
  });
});
