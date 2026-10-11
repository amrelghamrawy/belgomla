import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AccountOverview } from "@/components/store/AccountOverview";
import { PriceListView } from "@/components/store/PriceListView";
import { StoreHeader } from "@/components/store/StoreHeader";
import { StoreHome } from "@/components/store/StoreHome";

import { cart, cartItem, customer, facet, omit, page, storeProduct, tenantSettings } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { renderWithQuery, router, searchParams } from "../render";

const tree = [{ id: 1, name: "المرحلة الإعدادية", slug: "prep", kind: "books", image_url: null, children: [] }];

beforeEach(() => {
  router.push.mockReset();
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

describe("StoreHome", () => {
  it("shows the categories and the newest products", async () => {
    const fetchMock = stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/categories": json(200, { data: tree }),
      "GET /api/store/products": json(200, page([storeProduct()])),
    });
    renderWithQuery(<StoreHome />);

    expect(await screen.findByRole("link", { name: "المرحلة الإعدادية" })).toHaveAttribute("href", "/c/prep");
    expect(await screen.findByRole("link", { name: "الأضواء علوم أولى إعدادي" })).toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/store/products")[0]?.url.search).toBe("?sort=-created_at&per_page=8");
  });

  it("D41 a store without categories shows a WhatsApp contact button", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings({ whatsapp: "+201001234567" }) }),
      "GET /api/store/categories": json(200, { data: [] }),
      "GET /api/store/products": json(200, page([])),
    });
    renderWithQuery(<StoreHome />);

    expect(await screen.findByRole("heading", { name: "قريبًا" })).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.getByRole("link", { name: "تواصل عبر واتساب" })).toHaveAttribute(
        "href",
        "https://wa.me/201001234567",
      ),
    );
  });

  it("D41 falls back to the phone when the store has no WhatsApp", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings({ phone: "+201001234567" }) }),
      "GET /api/store/categories": json(200, { data: [] }),
      "GET /api/store/products": json(200, page([])),
    });
    renderWithQuery(<StoreHome />);

    expect(await screen.findByRole("link", { name: "اتصل بنا" })).toHaveAttribute("href", "tel:+201001234567");
  });
});

describe("StoreHeader", () => {
  it("shows how many lines the logged-in customer's cart has", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/cart": json(200, { data: cart({ items: [cartItem(), cartItem({ sku_id: 502 })] }) }),
    });
    renderWithQuery(<StoreHeader />);

    expect(await screen.findByRole("link", { name: "السلة، 2 صنف" })).toHaveAttribute("href", "/cart");
    expect(screen.getByRole("link", { name: "حسابي" })).toHaveAttribute("href", "/account");
  });

  it("does not ask for a guest's cart", async () => {
    const fetchMock = stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/me": envelopeError(401, "unauthenticated", "x"),
    });
    renderWithQuery(<StoreHeader />);

    expect(await screen.findByRole("link", { name: "تسجيل الدخول" })).toHaveAttribute("href", "/account/login");
    expect(callsTo(fetchMock, "GET /api/store/cart")).toHaveLength(0);
  });

  it("searches the whole store", async () => {
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/me": envelopeError(401, "unauthenticated", "x"),
    });
    const user = userEvent.setup();
    renderWithQuery(<StoreHeader />);

    await user.type(screen.getByRole("searchbox", { name: "بحث" }), "علوم الأضواء{Enter}");

    expect(router.push).toHaveBeenCalledWith(`/search?q=${encodeURIComponent("علوم الأضواء")}`);
  });
});

describe("PriceListView", () => {
  const rows = [
    {
      id: 1,
      name: "الأضواء علوم",
      slug: "a",
      kind: "books",
      code: "BK-001",
      unit: "نسخة",
      moq: 1,
      cover_price: "120.00",
      base_price: "96.00",
      availability: "available",
    },
    {
      id: 2,
      name: "المعلم علوم",
      slug: "b",
      kind: "books",
      code: "BK-002",
      unit: "كرتونة",
      moq: 2,
      cover_price: null,
      base_price: "80.00",
      availability: "coming_soon",
    },
  ];

  function stubList(meta: Record<string, unknown> = {}, data: unknown[] = rows) {
    return stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/categories": json(200, { data: tree }),
      "GET /api/store/filters": json(200, { data: [facet()] }),
      "GET /api/store/price-list": json(200, {
        data,
        meta: { count: data.length, truncated: false, prices_hidden: false, ...meta },
      }),
    });
  }

  it("BR-P13 lists name, code, unit, cover and wholesale price, MOQ and availability for the URL's selection", async () => {
    searchParams.value = new URLSearchParams("category=prep&f[grade]=7");
    const fetchMock = stubList();
    renderWithQuery(<PriceListView />);

    const table = await screen.findByTestId("price-list");
    expect(screen.getByRole("heading", { name: "قائمة الأسعار — المرحلة الإعدادية" })).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "سعر الغلاف" })).toBeInTheDocument();
    expect(table).toHaveTextContent("الأضواء علومBK-001نسخة120.00 ج.م96.00 ج.م1متوفر");
    expect(table).toHaveTextContent("المعلم علومBK-002كرتونة—80.00 ج.م2قريبًا");
    expect(callsTo(fetchMock, "GET /api/store/price-list")[0]?.url.search).toBe("?category=prep&f%5Bgrade%5D=7");
  });

  it("D68 names the reference column after the category's section, and both ways without a category", async () => {
    searchParams.value = new URLSearchParams("category=supplies");
    stubApi({
      "GET /api/store/tenant": json(200, { data: tenantSettings() }),
      "GET /api/store/categories": json(200, {
        data: [
          ...tree,
          { id: 2, name: "أدوات دراسية", slug: "supplies", kind: "stationery", image_url: null, children: [] },
        ],
      }),
      "GET /api/store/filters": json(200, { data: [] }),
      "GET /api/store/price-list": json(200, {
        data: rows,
        meta: { count: 2, truncated: false, prices_hidden: false },
      }),
    });
    const { unmount } = renderWithQuery(<PriceListView />);

    await screen.findByTestId("price-list");
    expect(screen.getByRole("columnheader", { name: "سعر القطاعي" })).toBeInTheDocument();
    unmount();

    searchParams.value = new URLSearchParams();
    stubList();
    renderWithQuery(<PriceListView />);

    await screen.findByTestId("price-list");
    expect(screen.getByRole("columnheader", { name: "سعر الغلاف / القطاعي" })).toBeInTheDocument();
  });

  it("D1 leaves the price columns out when prices are hidden", async () => {
    stubList(
      { prices_hidden: true },
      rows.map((row) => omit(row, "cover_price", "base_price")),
    );
    renderWithQuery(<PriceListView />);

    await screen.findByTestId("price-list");
    expect(screen.queryByRole("columnheader", { name: "سعر الجملة" })).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "سجل الدخول لعرض الأسعار في القائمة" })).toBeInTheDocument();
  });

  it("says when the list was cut at the limit", async () => {
    stubList({ truncated: true, count: 2000 });
    renderWithQuery(<PriceListView />);

    expect(await screen.findByText("تعرض القائمة أول 2000 منتج فقط. حدد التصفية لتقليل النتائج.")).toBeInTheDocument();
  });

  it("BR-P13 the share button copies the page link", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    const success = vi.spyOn(toast, "success").mockImplementation(() => "t");
    stubList();
    renderWithQuery(<PriceListView />);

    // fireEvent, not userEvent: userEvent.setup() installs its own navigator.clipboard.
    fireEvent.click(screen.getByRole("button", { name: "مشاركة الرابط" }));

    await waitFor(() => expect(success).toHaveBeenCalledWith("تم نسخ الرابط"));
    expect(writeText).toHaveBeenCalledWith(window.location.href);
    Reflect.deleteProperty(navigator, "clipboard");
  });

  it("BR-P13 without the Clipboard API (an http store) copies through a selection", async () => {
    const execCommand = vi.fn().mockReturnValue(true);
    Object.defineProperty(document, "execCommand", { value: execCommand, configurable: true });
    const success = vi.spyOn(toast, "success").mockImplementation(() => "t");
    stubList();
    renderWithQuery(<PriceListView />);

    fireEvent.click(screen.getByRole("button", { name: "مشاركة الرابط" }));

    await waitFor(() => expect(success).toHaveBeenCalledWith("تم نسخ الرابط"));
    expect(execCommand).toHaveBeenCalledWith("copy");
    Reflect.deleteProperty(document, "execCommand");
  });

  it("BR-P13 a search narrows the list and keeps the category", async () => {
    searchParams.value = new URLSearchParams("category=prep");
    stubList();
    const user = userEvent.setup();
    renderWithQuery(<PriceListView />);

    await user.type(screen.getByRole("searchbox", { name: "بحث في القائمة" }), " علوم {Enter}");

    expect(router.replace).toHaveBeenCalledWith("/price-list?q=%D8%B9%D9%84%D9%88%D9%85&category=prep", {
      scroll: false,
    });
  });

  it("BR-P13 an empty search removes q from the URL", async () => {
    searchParams.value = new URLSearchParams("q=علوم");
    stubList();
    const user = userEvent.setup();
    renderWithQuery(<PriceListView />);

    await user.clear(screen.getByRole("searchbox", { name: "بحث في القائمة" }));
    await user.keyboard("{Enter}");

    expect(router.replace).toHaveBeenCalledWith("/price-list", { scroll: false });
  });

  it("BR-P13 choosing a category writes it into the URL and drops the facets of the previous one", async () => {
    searchParams.value = new URLSearchParams("f[grade]=7&q=علوم");
    stubList();
    const user = userEvent.setup();
    renderWithQuery(<PriceListView />);
    const select = screen.getByRole("combobox", { name: "القسم" });
    await screen.findByRole("option", { name: "المرحلة الإعدادية" });

    await user.selectOptions(select, "prep");

    expect(router.replace).toHaveBeenCalledWith("/price-list?q=%D8%B9%D9%84%D9%88%D9%85&category=prep", {
      scroll: false,
    });
  });

  it("BR-P08 a facet from the drawer is added to the list's URL", async () => {
    searchParams.value = new URLSearchParams("category=prep");
    const fetchMock = stubList();
    const user = userEvent.setup();
    renderWithQuery(<PriceListView />);
    await user.click(screen.getByRole("button", { name: "تصفية" }));
    const drawer = await screen.findByRole("dialog", { name: "تصفية المنتجات" });

    await user.click(within(drawer).getByRole("radio", { name: /أولى إعدادي\s*16/ }));

    expect(router.replace).toHaveBeenCalledWith("/price-list?f%5Bgrade%5D=7&category=prep", { scroll: false });
    expect(callsTo(fetchMock, "GET /api/store/filters")[0]?.url.search).toBe("?category=prep");
  });

  it("clearing the filters returns to the whole list, and the button shows only when something is selected", async () => {
    searchParams.value = new URLSearchParams("category=prep&f[grade]=7&q=علوم");
    stubList();
    const user = userEvent.setup();
    const { unmount } = renderWithQuery(<PriceListView />);

    await user.click(screen.getByRole("button", { name: "مسح التصفية" }));

    expect(router.replace).toHaveBeenCalledWith("/price-list", { scroll: false });
    unmount();
    searchParams.value = new URLSearchParams();
    renderWithQuery(<PriceListView />);
    expect(screen.queryByRole("button", { name: "مسح التصفية" })).not.toBeInTheDocument();
  });

  it("the print button opens the browser's print dialog", async () => {
    const print = vi.spyOn(window, "print").mockImplementation(() => undefined);
    stubList();
    const user = userEvent.setup();
    renderWithQuery(<PriceListView />);

    await user.click(screen.getByRole("button", { name: "طباعة" }));

    expect(print).toHaveBeenCalled();
  });
});

describe("AccountOverview", () => {
  it("D63 shows the profile read-only with links to orders and addresses", async () => {
    stubApi({ "GET /api/store/me": json(200, { data: customer() }) });
    renderWithQuery(<AccountOverview />);

    expect(await screen.findByText("مكتبة الأمل")).toBeInTheDocument();
    expect(screen.getByText("01012345678")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "طلباتي" })).toHaveAttribute("href", "/account/orders");
    expect(screen.getByRole("link", { name: "عناويني" })).toHaveAttribute("href", "/account/addresses");
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("BR-CU05 explains a status that cannot order", async () => {
    stubApi({
      "GET /api/store/me": json(200, {
        data: customer({ status: "pending_approval", status_label: "في انتظار الموافقة" }),
      }),
    });
    renderWithQuery(<AccountOverview />);

    expect(await screen.findByText("حسابك في انتظار موافقة المتجر")).toBeInTheDocument();
  });
});
