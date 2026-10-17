import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProductDetail } from "@/components/store/ProductDetail";

import { cart, comingSoonSku, customer, storeProduct, storeSku } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { params, renderWithQuery, router } from "../render";

const SLUG = "الأضواء-علوم-أولى-إعدادي";

beforeEach(() => {
  router.push.mockReset();
  params.value = { slug: encodeURIComponent(SLUG) };
});

function stubProduct(product = storeProduct(), me: unknown = customer()) {
  return stubApi({
    [`GET /api/store/products/${encodeURIComponent(SLUG)}`]: json(200, { data: product }),
    "GET /api/store/me": me === null ? envelopeError(401, "unauthenticated", "x") : json(200, { data: me }),
    "POST /api/store/cart/items": json(200, { data: cart() }),
  });
}

describe("ProductDetail", () => {
  it("shows the prices, the tiers, the MOQ, the unit and its conversion", async () => {
    stubProduct();
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByRole("heading", { level: 1, name: "الأضواء علوم أولى إعدادي" })).toBeInTheDocument();
    expect(screen.getByText("90.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText(/سعر الغلاف:/)).toBeInTheDocument();
    expect(screen.getByText("هامش ربحك 25%")).toBeInTheDocument();
    expect(screen.getByRole("table", { name: "أسعار الكميات" })).toHaveTextContent("من 10 كرتونة85.00 ج.م");
    expect(screen.getByText("(كرتونة = 20 نسخة)")).toBeInTheDocument();
    expect(screen.getByText("BK-001")).toBeInTheDocument();
    expect(screen.getByText("السنة الدراسية")).toBeInTheDocument();
  });

  it("BR-P10 lists the other editions of the book", async () => {
    const revision = storeProduct({ id: 12, name: "المراجعة النهائية علوم", slug: "revision" });
    stubProduct(storeProduct({ related: [{ type: "revision_of", direction: "incoming", product: revision }] }));
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByRole("heading", { name: "نسخ أخرى من هذا الكتاب" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "المراجعة النهائية علوم" })).toHaveAttribute("href", "/p/revision");
  });

  it("BR-MOQ01 starts the quantity at the MOQ and adds it to the cart", async () => {
    const success = vi.spyOn(toast, "success").mockImplementation(() => "t");
    const fetchMock = stubProduct();
    const user = userEvent.setup();
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByRole("spinbutton", { name: "الكمية" })).toHaveValue(2);
    expect(screen.getByText("أقل كمية 2 كرتونة")).toBeInTheDocument();
    expect(screen.queryByText(/المتاح/)).not.toBeInTheDocument();
    expect(screen.getByText("الإجمالي 40 نسخة")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "زيادة الكمية" }));
    expect(screen.getByText("الإجمالي 60 نسخة")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "أضف إلى السلة" }));

    await waitFor(() => expect(success).toHaveBeenCalled());
    expect(callsTo(fetchMock, "POST /api/store/cart/items")[0]?.body).toEqual({ sku_id: 501, quantity: 3 });
  });

  it("D21 a coming-soon product cannot be added", async () => {
    stubProduct(storeProduct({ status: "coming_soon", sku: comingSoonSku() }));
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByRole("button", { name: "قريبًا" })).toBeDisabled();
    expect(screen.queryByRole("spinbutton")).not.toBeInTheDocument();
  });

  it("a product whose stock is below the MOQ cannot be added", async () => {
    stubProduct(storeProduct({ sku: storeSku({ available: 20, in_stock: true }) }));
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByRole("button", { name: "غير متوفر" })).toBeDisabled();
  });

  it("BR-CU06 a guest is sent to log in and back here", async () => {
    stubProduct(storeProduct(), null);
    const user = userEvent.setup();
    renderWithQuery(<ProductDetail />);
    await screen.findByRole("heading", { level: 1 });
    await waitFor(() => expect(screen.getByRole("button", { name: "أضف إلى السلة" })).toBeEnabled());

    await user.click(screen.getByRole("button", { name: "أضف إلى السلة" }));

    expect(router.push).toHaveBeenCalledWith(
      `/account/login?next=${encodeURIComponent(`/p/${encodeURIComponent(SLUG)}`)}`,
    );
  });

  it("D53 prices_hidden from the cart sends the shopper to log in", async () => {
    stubApi({
      [`GET /api/store/products/${encodeURIComponent(SLUG)}`]: json(200, { data: storeProduct() }),
      "GET /api/store/me": json(200, { data: customer() }),
      "POST /api/store/cart/items": envelopeError(403, "prices_hidden", "سجل الدخول"),
    });
    const user = userEvent.setup();
    renderWithQuery(<ProductDetail />);

    await user.click(await screen.findByRole("button", { name: "أضف إلى السلة" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith(expect.stringMatching(/^\/account\/login\?next=/)));
  });

  it("shows the server's reason when the cart refuses the line", async () => {
    const error = vi.spyOn(toast, "error").mockImplementation(() => "t");
    stubApi({
      [`GET /api/store/products/${encodeURIComponent(SLUG)}`]: json(200, { data: storeProduct() }),
      "GET /api/store/me": json(200, { data: customer() }),
      "POST /api/store/cart/items": envelopeError(422, "cart_line_invalid", "الكمية غير متوفرة"),
    });
    const user = userEvent.setup();
    renderWithQuery(<ProductDetail />);

    await user.click(await screen.findByRole("button", { name: "أضف إلى السلة" }));

    await waitFor(() => expect(error).toHaveBeenCalledWith("الكمية غير متوفرة"));
  });

  it("shows not found for an unknown or inactive product", async () => {
    stubApi({
      [`GET /api/store/products/${encodeURIComponent(SLUG)}`]: envelopeError(404, "not_found", "العنصر غير موجود"),
      "GET /api/store/me": json(200, { data: customer() }),
    });
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByRole("heading", { name: "الصفحة غير موجودة" })).toBeInTheDocument();
  });
});
