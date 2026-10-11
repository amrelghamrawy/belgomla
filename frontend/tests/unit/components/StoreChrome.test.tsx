import { screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it } from "vitest";

import { CategoryGrid } from "@/components/store/CategoryGrid";
import { OrderSheet } from "@/components/store/OrderSheet";
import { StoreBottomNav } from "@/components/store/StoreBottomNav";
import { StoreFooter } from "@/components/store/StoreFooter";
import { StoreHeader } from "@/components/store/StoreHeader";
import { StoreHome } from "@/components/store/StoreHome";
import type { Category } from "@/lib/api/types";

import { cart, cartItem, customer, page, storeProduct, tenantSettings } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { pathname, renderWithQuery } from "../render";

const tree: Category[] = [
  {
    id: 1,
    name: "المرحلة الإعدادية",
    slug: "prep",
    kind: "books",
    image_url: null,
    children: [{ id: 2, name: "أولى إعدادي", slug: "prep-1", kind: "books", image_url: null, children: [] }],
  },
  { id: 3, name: "المرحلة الثانوية", slug: "secondary", kind: "books", image_url: null, children: [] },
];
const storeWithMinimum = tenantSettings({
  whatsapp: "+201001234567",
  address: "12 شارع الفجالة، القاهرة",
  description: "كتب خارجية بالجملة.",
  mov: { enabled: true, amount: "500.00" },
});
const guest = { "GET /api/store/me": envelopeError(401, "unauthenticated", "x") };
const signedIn = (lines = cart()) => ({
  "GET /api/store/me": json(200, { data: customer() }),
  "GET /api/store/cart": json(200, { data: lines }),
});

beforeEach(() => {
  pathname.value = "/";
});

describe("StoreHeader (D67)", () => {
  it("shows the minimum order, delivery and WhatsApp in the top strip", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: storeWithMinimum }),
      "GET /api/store/categories": json(200, { data: tree }),
      ...guest,
    });
    renderWithQuery(<StoreHeader />);

    expect(await screen.findByText("الحد الأدنى للطلب 500.00 ج.م")).toBeInTheDocument();
    expect(screen.getByText("التوصيل بواسطة المتجر")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /واتساب/ })).toHaveAttribute("href", "https://wa.me/201001234567");
  });

  it("shows the cart total from the server next to the cart", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/categories": json(200, { data: tree }),
      ...signedIn(cart({ items_total: "1080.00" })),
    });
    renderWithQuery(<StoreHeader />);

    const cartLink = await screen.findByRole("link", { name: "السلة، 1 صنف" });
    await waitFor(() => expect(within(cartLink).getByText("1,080.00 ج.م")).toBeInTheDocument());
  });

  it("lists the top categories and marks the current one", async () => {
    pathname.value = "/c/prep";
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/categories": json(200, { data: tree }),
      ...guest,
    });
    renderWithQuery(<StoreHeader />);

    const nav = await screen.findByRole("navigation", { name: "الأقسام" });
    expect(within(nav).getByRole("link", { name: "المرحلة الإعدادية" })).toHaveClass("bg-brand-soft");
    expect(within(nav).getByRole("link", { name: "قائمة الأسعار" })).toHaveAttribute("href", "/price-list");
  });

  it("shows the store's initial when it has no logo", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/categories": json(200, { data: [] }),
      ...guest,
    });
    renderWithQuery(<StoreHeader />);

    const home = await screen.findByRole("link", { name: "الرئيسية" });
    await waitFor(() => expect(within(home).getByText("ن")).toBeInTheDocument());
  });
});

describe("StoreBottomNav (D67)", () => {
  it("links the five phone destinations and marks the current page", async () => {
    stubApi(guest);
    renderWithQuery(<StoreBottomNav />);

    const nav = screen.getByRole("navigation", { name: "التنقل الرئيسي" });
    expect(within(nav).getAllByRole("link")).toHaveLength(5);
    expect(within(nav).getByRole("link", { name: "الرئيسية" })).toHaveAttribute("aria-current", "page");
    await waitFor(() =>
      expect(within(nav).getByRole("link", { name: "حسابي" })).toHaveAttribute("href", "/account/login"),
    );
  });

  it.each([["/p/some-book"], ["/cart"], ["/checkout"]])("is left out on %s, which has its own bottom bar", (path) => {
    pathname.value = path;
    stubApi(guest);
    renderWithQuery(<StoreBottomNav />);

    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
  });

  it("BR-MOV02 shows the cart total and what is left to the minimum above the bar", async () => {
    stubApi(signedIn(cart({ items_total: "300.00", mov: { enabled: true, amount: "500.00", satisfied: false } })));
    renderWithQuery(<StoreBottomNav />);

    const strip = await screen.findByTestId("bottom-cart-strip");
    expect(strip).toHaveAttribute("href", "/cart");
    expect(strip).toHaveTextContent("300.00 ج.م");
    expect(strip).toHaveTextContent("باقي 200.00 ج.م للحد الأدنى للطلب");
  });

  it("has no cart strip when the cart is empty", async () => {
    const fetchMock = stubApi(signedIn(cart({ items: [], items_total: "0.00" })));
    renderWithQuery(<StoreBottomNav />);

    await waitFor(() => expect(callsTo(fetchMock, "GET /api/store/cart")).toHaveLength(1));
    expect(screen.queryByTestId("bottom-cart-strip")).not.toBeInTheDocument();
  });
});

describe("OrderSheet (D67)", () => {
  it("asks a guest to log in or register and shows the minimum order", async () => {
    stubApi({ "GET /api/store/tenant": json(200, { data: storeWithMinimum }), ...guest });
    renderWithQuery(<OrderSheet />);

    expect(await screen.findByRole("link", { name: "تسجيل الدخول" })).toHaveAttribute("href", "/account/login");
    expect(screen.getByRole("link", { name: "إنشاء حساب" })).toHaveAttribute("href", "/account/register");
    expect(await screen.findByText("الحد الأدنى للطلب 500.00 ج.م")).toBeInTheDocument();
  });

  it("lists the newest cart lines with the server's totals and the progress to the minimum", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: storeWithMinimum }),
      ...signedIn(
        cart({
          items: [cartItem({ quantity: 2, line_total: "180.00" })],
          items_total: "180.00",
          mov: { enabled: true, amount: "500.00", satisfied: false },
        }),
      ),
    });
    renderWithQuery(<OrderSheet />);

    expect(await screen.findByText("الأضواء علوم أولى إعدادي")).toBeInTheDocument();
    expect(screen.getByTestId("sheet-total")).toHaveTextContent("180.00 ج.م");
    expect(screen.getByTestId("sheet-mov")).toHaveTextContent("باقي 320.00 ج.م للحد الأدنى للطلب");
    expect(screen.getByRole("link", { name: "مراجعة الطلبية" })).toHaveAttribute("href", "/cart");
  });

  it("folds lines beyond the newest three into a count", async () => {
    const items = [501, 502, 503, 504, 505].map((sku_id) => cartItem({ sku_id, product_name: `كتاب ${sku_id}` }));
    stubApi({ "GET /api/store/tenant": json(200, { data: tenantSettings() }), ...signedIn(cart({ items })) });
    renderWithQuery(<OrderSheet />);

    expect(await screen.findByText("و2 أصناف أخرى")).toBeInTheDocument();
    expect(screen.getByText("كتاب 505")).toBeInTheDocument();
    expect(screen.queryByText("كتاب 501")).not.toBeInTheDocument();
  });

  it("invites an active customer with an empty cart to add products", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      ...signedIn(cart({ items: [], items_total: "0.00" })),
    });
    renderWithQuery(<OrderSheet />);

    expect(await screen.findByText(/لم تضف منتجات بعد/)).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "مراجعة الطلبية" })).not.toBeInTheDocument();
  });

  it("shows the account status to a customer who is not active yet", async () => {
    const fetchMock = stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/me": json(200, {
        data: customer({ status: "pending_approval", status_label: "في انتظار الموافقة" }),
      }),
    });
    renderWithQuery(<OrderSheet />);

    expect(await screen.findByText("حالة حسابك: في انتظار الموافقة")).toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/store/cart")).toHaveLength(0);
  });
});

describe("StoreHome (D67)", () => {
  function stubHome(me = guest, tenant = storeWithMinimum) {
    return stubApi({
      "GET /api/store/tenant": json(200, { data: tenant }),
      "GET /api/store/categories": json(200, { data: tree }),
      "GET /api/store/products": ({ url }) =>
        url.searchParams.get("category") === "secondary"
          ? json(200, page([]))
          : json(200, page([storeProduct()], { total: 34 })),
      ...me,
    });
  }

  it("puts the store's name, description and ordering facts in the brand band", async () => {
    stubHome();
    renderWithQuery(<StoreHome />);

    expect(await screen.findByRole("heading", { level: 1, name: "مكتبة النور" })).toBeInTheDocument();
    expect(screen.getByText("كتب خارجية بالجملة.")).toBeInTheDocument();
    expect(screen.getByText("12 شارع الفجالة، القاهرة")).toBeInTheDocument();
    expect(await screen.findByText("34 منتج")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "قائمة الأسعار الكاملة" })).toHaveAttribute("href", "/price-list");
    expect(screen.getByRole("link", { name: "اطلب على واتساب" })).toHaveAttribute("href", "https://wa.me/201001234567");
  });

  it("adds a row of products for a top category and skips an empty one", async () => {
    const fetchMock = stubHome();
    renderWithQuery(<StoreHome />);

    expect(await screen.findByRole("heading", { level: 2, name: "المرحلة الإعدادية" })).toBeInTheDocument();
    await waitFor(() => expect(callsTo(fetchMock, "GET /api/store/products")).toHaveLength(3));
    expect(screen.queryByRole("heading", { level: 2, name: "المرحلة الثانوية" })).not.toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/store/products")[1]?.url.search).toBe(
      "?category=prep&sort=-created_at&per_page=8",
    );
  });

  it("explains how to order to a guest, with the approval step when the store requires it", async () => {
    stubHome(guest, tenantSettings({ require_customer_approval: true }));
    renderWithQuery(<StoreHome />);

    expect(await screen.findByRole("heading", { level: 2, name: "كيف تطلب" })).toBeInTheDocument();
    expect(screen.getByText(/ويراجعه المتجر/)).toBeInTheDocument();
  });

  it("does not explain ordering to a signed-in customer", async () => {
    stubHome(signedIn());
    renderWithQuery(<StoreHome />);

    await screen.findByRole("link", { name: "مراجعة الطلبية" });
    expect(screen.queryByRole("heading", { level: 2, name: "كيف تطلب" })).not.toBeInTheDocument();
  });
});

describe("CategoryGrid (D67)", () => {
  it("links each category and its subcategories", () => {
    renderWithQuery(<CategoryGrid categories={tree} />);

    expect(screen.getByRole("link", { name: "المرحلة الإعدادية" })).toHaveAttribute("href", "/c/prep");
    expect(screen.getByRole("link", { name: "أولى إعدادي" })).toHaveAttribute("href", "/c/prep-1");
  });
});

describe("StoreFooter (D67)", () => {
  it("shows the contact details, the hours and the store's name", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, {
        data: tenantSettings({
          phone: "+201001234567",
          address: "12 شارع الفجالة، القاهرة",
          working_hours: [{ day: 6, from: "09:00", to: "21:00" }],
        }),
      }),
    });
    renderWithQuery(<StoreFooter />);

    expect(await screen.findByText("العنوان: 12 شارع الفجالة، القاهرة")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "01001234567" })).toHaveAttribute("href", "tel:+201001234567");
    expect(screen.getByText("السبت")).toBeInTheDocument();
    expect(screen.getByText("© مكتبة النور. كل الحقوق محفوظة.")).toBeInTheDocument();
  });
});
