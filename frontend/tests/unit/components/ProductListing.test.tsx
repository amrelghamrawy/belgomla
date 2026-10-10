import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it } from "vitest";

import { ProductListing } from "@/components/store/ProductListing";

import { facet, page, storeProduct } from "../fixtures";
import { callsTo, json, stubApi } from "../http";
import { pathname, renderWithQuery, router, searchParams } from "../render";

const tree = [
  {
    id: 1,
    name: "المرحلة الإعدادية",
    slug: "prep",
    image_url: null,
    children: [
      {
        id: 2,
        name: "أولى إعدادي",
        slug: "first-prep",
        image_url: null,
        children: [{ id: 3, name: "الترم الأول", slug: "first-term", image_url: null, children: [] }],
      },
    ],
  },
];

function stubCatalog(products = [storeProduct()]) {
  return stubApi({
    "GET /api/store/categories": json(200, { data: tree }),
    "GET /api/store/filters": json(200, {
      data: [facet(), facet({ key: "subject", label: "المادة", options: [{ id: 15, label: "علوم", count: 9 }] })],
    }),
    "GET /api/store/products": json(200, page(products, { total: 31 })),
  });
}

beforeEach(() => {
  router.replace.mockReset();
  pathname.value = "/c/prep";
  searchParams.value = new URLSearchParams();
});

describe("ProductListing", () => {
  it("shows the category, its subcategories and its products", async () => {
    stubCatalog();
    renderWithQuery(<ProductListing category="prep" />);

    expect(await screen.findByRole("heading", { level: 1, name: "المرحلة الإعدادية" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "أولى إعدادي" })).toHaveAttribute("href", "/c/first-prep");
    expect(await screen.findByRole("link", { name: "الأضواء علوم أولى إعدادي" })).toBeInTheDocument();
    expect(screen.getByText("31 منتج")).toBeInTheDocument();
  });

  it("shows every parent of a subcategory in the breadcrumbs", async () => {
    pathname.value = "/c/first-term";
    stubCatalog();
    renderWithQuery(<ProductListing category="first-term" />);

    const crumbs = await screen.findByRole("navigation", { name: "مسار التصفح" });
    expect(within(crumbs).getAllByRole("link").map((link) => [link.textContent, link.getAttribute("href")])).toEqual([
      ["الرئيسية", "/"],
      ["المرحلة الإعدادية", "/c/prep"],
      ["أولى إعدادي", "/c/first-prep"],
    ]);
    expect(within(crumbs).getByText("الترم الأول")).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("heading", { level: 1, name: "الترم الأول" })).toBeInTheDocument();
  });
  it("BR-P08 asks the API for the selection in the URL", async () => {
    searchParams.value = new URLSearchParams("f[grade]=7&sort=price");
    const fetchMock = stubCatalog();
    renderWithQuery(<ProductListing category="prep" />);

    await screen.findByRole("link", { name: "الأضواء علوم أولى إعدادي" });

    const [products] = callsTo(fetchMock, "GET /api/store/products");
    const [filters] = callsTo(fetchMock, "GET /api/store/filters");
    expect(products?.url.search).toBe("?category=prep&sort=price&f%5Bgrade%5D=7&page=1&per_page=24");
    expect(filters?.url.search).toBe("?category=prep&f%5Bgrade%5D=7");
  });

  it("BR-P08 D18 lists the facets in the server's order with their counts", async () => {
    stubCatalog();
    const user = userEvent.setup();
    renderWithQuery(<ProductListing category="prep" />);

    await user.click(await screen.findByRole("button", { name: "تصفية" }));

    const drawer = await screen.findByRole("dialog", { name: "تصفية المنتجات" });
    const groups = within(drawer).getAllByRole("radiogroup");
    expect(groups.map((group) => group.getAttribute("aria-label"))).toEqual(["السنة الدراسية", "المادة"]);
    expect(within(groups[0] as HTMLElement).getByRole("radio", { name: /أولى إعدادي\s*16/ })).toBeInTheDocument();
  });

  it("opens the first two groups and any group with a selection; the rest collapse to their current choice", async () => {
    searchParams.value = new URLSearchParams("f[term]=21");
    stubApi({
      "GET /api/store/categories": json(200, { data: tree }),
      "GET /api/store/filters": json(200, {
        data: [
          facet(),
          facet({ key: "subject", label: "المادة", options: [{ id: 15, label: "علوم", count: 9 }] }),
          facet({ key: "publisher", label: "الشركة", options: [{ id: 30, label: "الأضواء", count: 4 }] }),
          facet({ key: "term", label: "الترم", options: [{ id: 21, label: "الترم الأول", count: 3 }] }),
        ],
      }),
      "GET /api/store/products": json(200, page([storeProduct()], { total: 3 })),
    });
    const user = userEvent.setup();
    renderWithQuery(<ProductListing category="prep" />);
    await user.click(await screen.findByRole("button", { name: "تصفية (1)" }));
    const drawer = await screen.findByRole("dialog", { name: "تصفية المنتجات" });

    const toggle = (name: RegExp) => within(drawer).getByRole("button", { name });
    expect(toggle(/^السنة الدراسية/)).toHaveAttribute("aria-expanded", "true");
    expect(toggle(/^المادة/)).toHaveAttribute("aria-expanded", "true");
    expect(toggle(/^الشركة\s*الكل/)).toHaveAttribute("aria-expanded", "false");
    expect(toggle(/^الترم\s*الترم الأول/)).toHaveAttribute("aria-expanded", "true");
    expect(within(drawer).queryByRole("radio", { name: /الأضواء/ })).not.toBeInTheDocument();

    await user.click(toggle(/^الشركة/));

    expect(within(drawer).getByRole("radio", { name: /الأضواء\s*4/ })).toBeInTheDocument();
  });

  it("shows the first eight options of a long group until «show all», and searches a group past twelve", async () => {
    const options = Array.from({ length: 14 }, (_, index) => ({ id: 100 + index, label: `مادة ${index + 1}`, count: 1 }));
    stubApi({
      "GET /api/store/categories": json(200, { data: tree }),
      "GET /api/store/filters": json(200, { data: [facet({ key: "subject", label: "المادة", options })] }),
      "GET /api/store/products": json(200, page([storeProduct()], { total: 14 })),
    });
    const user = userEvent.setup();
    renderWithQuery(<ProductListing category="prep" />);
    await user.click(await screen.findByRole("button", { name: "تصفية" }));
    const drawer = await screen.findByRole("dialog", { name: "تصفية المنتجات" });

    // «الكل» + the first eight.
    expect(within(drawer).getAllByRole("radio")).toHaveLength(9);
    await user.click(within(drawer).getByRole("button", { name: "عرض الكل (14)" }));
    expect(within(drawer).getAllByRole("radio")).toHaveLength(15);
    await user.click(within(drawer).getByRole("button", { name: "عرض أقل" }));

    await user.type(within(drawer).getByRole("searchbox", { name: "ابحث في المادة" }), "مادة 13");
    expect(within(drawer).getAllByRole("radio").map((radio) => radio.textContent)).toEqual(["مادة 131"]);

    await user.clear(within(drawer).getByRole("searchbox"));
    await user.type(within(drawer).getByRole("searchbox"), "رياضيات");
    expect(within(drawer).queryByRole("radio")).not.toBeInTheDocument();
    expect(within(drawer).getByText("لا توجد نتائج مطابقة")).toBeInTheDocument();
  });

  it("BR-P08 choosing an option writes f[key] into the URL", async () => {
    stubCatalog();
    const user = userEvent.setup();
    renderWithQuery(<ProductListing category="prep" />);
    await user.click(await screen.findByRole("button", { name: "تصفية" }));

    await user.click(await screen.findByRole("radio", { name: /أولى إعدادي/ }));

    expect(router.replace).toHaveBeenCalledWith("/c/prep?f%5Bgrade%5D=7", { scroll: false });
  });

  it("BR-P08 a second facet is added to the first (AND)", async () => {
    searchParams.value = new URLSearchParams("f[grade]=7");
    stubCatalog();
    const user = userEvent.setup();
    renderWithQuery(<ProductListing category="prep" />);
    await user.click(await screen.findByRole("button", { name: "تصفية (1)" }));

    await user.click(await screen.findByRole("radio", { name: /علوم/ }));

    expect(router.replace).toHaveBeenCalledWith("/c/prep?f%5Bgrade%5D=7&f%5Bsubject%5D=15", { scroll: false });
  });

  it("starts with the URL's selection checked, and clearing removes every facet", async () => {
    searchParams.value = new URLSearchParams("f[grade]=8&q=علوم");
    stubCatalog();
    const user = userEvent.setup();
    renderWithQuery(<ProductListing category="prep" />);
    await user.click(await screen.findByRole("button", { name: "تصفية (1)" }));

    expect(await screen.findByRole("radio", { name: /ثانية إعدادي/ })).toBeChecked();
    await user.click(screen.getByRole("button", { name: "مسح الكل" }));

    expect(router.replace).toHaveBeenCalledWith("/c/prep?q=%D8%B9%D9%84%D9%88%D9%85", { scroll: false });
  });

  it("keeps keyboard focus inside the open drawer and closes on Escape", async () => {
    stubCatalog();
    const user = userEvent.setup();
    renderWithQuery(<ProductListing category="prep" />);
    await user.click(await screen.findByRole("button", { name: "تصفية" }));
    const drawer = await screen.findByRole("dialog");

    for (let step = 0; step < 8; step++) {
      await user.tab();
      expect(drawer).toContainElement(document.activeElement as HTMLElement);
    }

    await user.keyboard("{Escape}");
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
  });

  it("changing the sort keeps the facets", async () => {
    searchParams.value = new URLSearchParams("f[grade]=7");
    stubCatalog();
    const user = userEvent.setup();
    renderWithQuery(<ProductListing category="prep" />);

    await user.selectOptions(await screen.findByRole("combobox", { name: "الترتيب" }), "-price");

    expect(router.replace).toHaveBeenCalledWith("/c/prep?sort=-price&f%5Bgrade%5D=7", { scroll: false });
  });

  it("has no price-list link of its own; the header carries it", async () => {
    stubCatalog();
    renderWithQuery(<ProductListing category="prep" />);

    await screen.findByRole("combobox", { name: "الترتيب" });
    expect(screen.queryByRole("link", { name: "قائمة الأسعار" })).not.toBeInTheDocument();
  });

  it("says when nothing matches", async () => {
    stubCatalog([]);
    renderWithQuery(<ProductListing category="prep" />);

    expect(await screen.findByText("لا توجد منتجات مطابقة")).toBeInTheDocument();
  });

  it("shows not found for an unknown category", async () => {
    stubCatalog();
    renderWithQuery(<ProductListing category="nope" />);

    expect(await screen.findByRole("heading", { name: "القسم غير موجود" })).toBeInTheDocument();
  });

  it("search results show the term and do not query without one", async () => {
    pathname.value = "/search";
    const fetchMock = stubCatalog();
    renderWithQuery(<ProductListing search />);

    expect(await screen.findByRole("heading", { name: "اكتب كلمة للبحث" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "تصفية" })).not.toBeInTheDocument();
    expect(callsTo(fetchMock, "GET /api/store/products")).toHaveLength(0);
  });

  it("search results reuse the listing with q", async () => {
    pathname.value = "/search";
    searchParams.value = new URLSearchParams("q=علوم");
    const fetchMock = stubCatalog();
    renderWithQuery(<ProductListing search />);

    expect(await screen.findByRole("heading", { name: "نتائج البحث عن «علوم»" })).toBeInTheDocument();
    await screen.findByRole("link", { name: "الأضواء علوم أولى إعدادي" });
    expect(callsTo(fetchMock, "GET /api/store/products")[0]?.url.searchParams.get("q")).toBe("علوم");
  });
});
