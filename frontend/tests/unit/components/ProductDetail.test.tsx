import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProductDetail } from "@/components/store/ProductDetail";

import { cart, comingSoonSku, customer, penProduct, storeProduct, storeSku, storeSkuUnit } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { params, renderWithQuery, router, searchParams } from "../render";

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
    expect(callsTo(fetchMock, "POST /api/store/cart/items")[0]?.body).toEqual({ sku_unit_id: 601, quantity: 3 });
  });

  it("D91 switches the price, MOQ and tiers to the unit picked and adds that unit", async () => {
    vi.spyOn(toast, "success").mockImplementation(() => "t");
    const piece = storeSku({
      unit: "قطعة",
      conversion_factor: 1,
      moq: 5,
      base_price: "10.00",
      tiers: [],
      available: 100,
    });
    const carton = storeSkuUnit({
      unit: "كرتونة",
      conversion_factor: 12,
      moq: 1,
      base_price: "110.00",
      tiers: [{ min_quantity: 5, unit_price: "105.00" }],
    });
    const fetchMock = stubProduct(storeProduct({ sku: { ...piece, units: [...(piece.units ?? []), carton] } }));
    const user = userEvent.setup();
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByText("10.00 ج.م")).toBeInTheDocument();
    expect(screen.getByRole("spinbutton", { name: "الكمية" })).toHaveValue(5);
    await user.click(screen.getByRole("radio", { name: "كرتونة (12)" }));

    expect(screen.getByRole("radio", { name: "كرتونة (12)" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByText("110.00 ج.م")).toBeInTheDocument();
    expect(screen.getByRole("table", { name: "أسعار الكميات" })).toHaveTextContent("من 5 كرتونة105.00 ج.م");
    expect(screen.getByRole("spinbutton", { name: "الكمية" })).toHaveValue(1);
    expect(screen.getByText("الإجمالي 12 نسخة")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "زيادة الكمية" }));
    await user.click(screen.getByRole("button", { name: "أضف إلى السلة" }));

    await waitFor(() =>
      expect(callsTo(fetchMock, "POST /api/store/cart/items")[0]?.body).toEqual({ sku_unit_id: 602, quantity: 2 }),
    );
  });

  it("D91 shows no unit picker for a SKU sold in one unit", async () => {
    stubProduct();
    renderWithQuery(<ProductDetail />);

    await screen.findByRole("heading", { level: 1 });
    expect(screen.queryByRole("radiogroup", { name: "اختر وحدة البيع" })).toBeNull();
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

describe("pre-ordering on the product page (BR-O14)", () => {
  const preorderable = (remaining: number | null) =>
    storeProduct({
      status: "coming_soon",
      sku: comingSoonSku(),
      preorder: { enabled: true, expected_at: "2026-12-01", remaining },
    });

  it("books from the MOQ without stock, with the expected day, the price note and what remains", async () => {
    const success = vi.spyOn(toast, "success").mockImplementation(() => "t");
    const fetchMock = stubProduct(preorderable(30));
    const user = userEvent.setup();
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByRole("spinbutton", { name: "الكمية" })).toHaveValue(2);
    expect(screen.getByTestId("preorder-terms")).toHaveTextContent(
      "متوقع 1 ديسمبر 2026 · السعر النهائي عند التوفر · متبقٍ للحجز 30 كرتونة",
    );
    await user.click(screen.getByRole("button", { name: "احجز الآن" }));

    await waitFor(() => expect(success).toHaveBeenCalledWith("أضيف إلى حجوزاتك في السلة", expect.anything()));
    expect(callsTo(fetchMock, "POST /api/store/cart/items")[0]?.body).toEqual({
      sku_unit_id: 601,
      quantity: 2,
      kind: "preorder",
    });
  });

  it("cannot book past the merchant's cap", async () => {
    stubProduct(preorderable(0));
    renderWithQuery(<ProductDetail />);

    expect(await screen.findByRole("button", { name: "اكتمل الحجز" })).toBeDisabled();
  });
});

describe("ProductDetail with variants (V2 Task 14)", () => {
  beforeEach(() => {
    router.replace.mockReset();
    params.value = { slug: "pen" };
    searchParams.value = new URLSearchParams();
  });

  function stubPen(product = penProduct()) {
    return stubApi({
      "GET /api/store/products/pen": json(200, { data: product }),
      "GET /api/store/me": json(200, { data: customer() }),
      "POST /api/store/cart/items": json(200, { data: cart() }),
    });
  }

  it("BR-P14 offers the colours, disabling the one without stock, and shows the product's SKU first", async () => {
    stubPen();
    renderWithQuery(<ProductDetail />);

    const colours = await screen.findByRole("radiogroup", { name: "اللون" });
    expect(within(colours).getByRole("radio", { name: "أزرق" })).toHaveAttribute("aria-checked", "true");
    expect(within(colours).getByRole("radio", { name: "أسود" })).toBeEnabled();
    expect(within(colours).getByRole("radio", { name: "أحمر (غير متوفر)" })).toBeDisabled();
    expect(screen.getByText("10.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("PEN-01-1")).toBeInTheDocument();
  });

  it("keeps the colour picked in the URL, and the price, code and cart line follow it", async () => {
    vi.spyOn(toast, "success").mockImplementation(() => "t");
    const fetchMock = stubPen();
    const user = userEvent.setup();
    const view = renderWithQuery(<ProductDetail />);

    await user.click(await screen.findByRole("radio", { name: "أسود" }));
    expect(router.replace).toHaveBeenCalledWith("/p/pen?sku=82", { scroll: false });

    searchParams.value = new URLSearchParams("sku=82");
    view.rerender(<ProductDetail />);

    expect(screen.getByRole("radio", { name: "أسود" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByText("12.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("PEN-01-2")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "أضف إلى السلة" }));

    await waitFor(() => expect(callsTo(fetchMock, "POST /api/store/cart/items")).toHaveLength(1));
    expect(callsTo(fetchMock, "POST /api/store/cart/items")[0]?.body).toEqual({ sku_unit_id: 702, quantity: 1 });
  });

  it("opens on the variant the URL names, and on the product's SKU when the URL names another", async () => {
    searchParams.value = new URLSearchParams("sku=82");
    stubPen();
    const view = renderWithQuery(<ProductDetail />);

    expect(await screen.findByText("PEN-01-2")).toBeInTheDocument();

    searchParams.value = new URLSearchParams("sku=999");
    view.rerender(<ProductDetail />);
    expect(screen.getByText("PEN-01-1")).toBeInTheDocument();
  });

  it("shows a swatch for a value with an image", async () => {
    const pen = penProduct();
    stubPen({
      ...pen,
      options: [
        {
          ...pen.options[0]!,
          values: pen.options[0]!.values.map((value) =>
            value.id === 2 ? { ...value, image_url: "/storage/p/black.jpg" } : value,
          ),
        },
      ],
    });
    renderWithQuery(<ProductDetail />);

    const black = await screen.findByRole("radio", { name: "أسود" });
    expect(within(black).getByRole("presentation", { hidden: true })).toHaveAttribute(
      "src",
      expect.stringContaining("black.jpg"),
    );
  });
});
