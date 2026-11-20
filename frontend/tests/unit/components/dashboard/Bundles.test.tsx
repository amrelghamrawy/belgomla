import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it } from "vitest";

import { BundleEditor, BundleForm } from "@/components/dashboard/catalog/BundleEditor";
import { GradeSetWizard } from "@/components/dashboard/catalog/GradeSetWizard";
import { ProductsPage } from "@/components/dashboard/catalog/ProductsPage";

import {
  bundleDashboardProduct,
  category,
  dashboardProduct,
  selectField,
  signedInAs,
  unit,
  variantProduct,
} from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery, router, searchParams } from "../../render";

const units = [unit({ id: 7, name: "طقم" }), unit({ id: 1, name: "نسخة" })];
const categories = [category()];

beforeEach(() => {
  router.push.mockReset();
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

/** The products the component picker finds: a book, a pen in two colours (one inactive) and a bundle. */
function stubPicker(routes: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({
    "GET /api/dashboard/products": json(
      200,
      page([
        dashboardProduct(),
        variantProduct(),
        bundleDashboardProduct({ id: 31, name: "طقم آخر", status: "active" }),
      ]),
    ),
    ...routes,
  });
}

async function pick(user: ReturnType<typeof userEvent.setup>, code: RegExp) {
  await user.click(screen.getByRole("button", { name: "إضافة صنف" }));
  await user.click(await screen.findByRole("option", { name: code }));
}

describe("BundleForm (BR-P15, D93)", () => {
  it("picks components by product search and shows the sum, price and saving live", async () => {
    stubPicker();
    const user = userEvent.setup();
    renderWithQuery(<BundleForm product={null} units={units} categories={categories} />);

    await user.click(screen.getByRole("button", { name: "إضافة صنف" }));
    const listbox = await screen.findByRole("listbox");
    // Variants and units are listed; the inactive black pen and the other bundle are not (one level only).
    await within(listbox).findByRole("option", { name: /ADW-SCI-1/ });
    expect(within(listbox).getByRole("option", { name: /PEN-01-1/ })).toBeInTheDocument();
    expect(within(listbox).queryByRole("option", { name: /PEN-01-2/ })).not.toBeInTheDocument();
    expect(within(listbox).queryByText("طقم آخر")).not.toBeInTheDocument();
    await user.click(within(listbox).getByRole("option", { name: /ADW-SCI-1/ }));
    await pick(user, /PEN-01-1/);

    expect(screen.getByText("قلم جاف (أزرق)")).toBeInTheDocument();
    expect(screen.getByTestId("bundle-sum")).toHaveTextContent("180.00 ج.م");
    // 0 % off by default: the price is the sum.
    expect(screen.getByTestId("bundle-price")).toHaveTextContent("180.00 ج.م");

    await user.clear(screen.getByLabelText("الكمية: قلم جاف (أزرق)"));
    await user.type(screen.getByLabelText("الكمية: قلم جاف (أزرق)"), "3");
    await user.clear(screen.getByLabelText("نسبة الخصم % *"));
    await user.type(screen.getByLabelText("نسبة الخصم % *"), "12.5");

    // (90 + 3 × 90) × 0.875 = 315.00
    expect(screen.getByTestId("bundle-sum")).toHaveTextContent("360.00 ج.م");
    expect(screen.getByTestId("bundle-price")).toHaveTextContent("315.00 ج.م");
    expect(screen.getByTestId("bundle-saving")).toHaveTextContent("45.00 ج.م");

    await user.selectOptions(screen.getByLabelText("طريقة التسعير"), "fixed");
    await user.clear(screen.getByLabelText("سعر الباقة *"));
    await user.type(screen.getByLabelText("سعر الباقة *"), "300");
    expect(screen.getByTestId("bundle-price")).toHaveTextContent("300.00 ج.م");
    expect(screen.getByTestId("bundle-saving")).toHaveTextContent("60.00 ج.م");
  });

  it("reorders and removes components", async () => {
    stubPicker();
    const user = userEvent.setup();
    renderWithQuery(<BundleForm product={bundleDashboardProduct()} units={units} categories={categories} />);

    const rows = () => within(screen.getByTestId("bundle-components")).getAllByRole("listitem");
    expect(rows()[0]).toHaveTextContent("الأضواء علوم");

    await user.click(screen.getByRole("button", { name: "تحريك لأعلى: قلم جاف (أزرق)" }));
    expect(rows()[0]).toHaveTextContent("قلم جاف");

    await user.click(screen.getByRole("button", { name: "حذف قلم جاف (أزرق) من الباقة" }));
    expect(rows()).toHaveLength(1);
    expect(screen.getByTestId("bundle-sum")).toHaveTextContent("90.00 ج.م");
  });

  it("refuses a bundle without components or with an invalid price, before sending", async () => {
    const fetchMock = stubPicker({ "POST /api/dashboard/bundles": json(201, { data: bundleDashboardProduct() }) });
    const user = userEvent.setup();
    renderWithQuery(<BundleForm product={null} units={units} categories={categories} />);

    await user.selectOptions(screen.getByLabelText("طريقة التسعير"), "fixed");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("أضف صنفًا واحدًا على الأقل")).toBeInTheDocument();
    expect(screen.getByText("سعر أكبر من صفر مثل 120.00")).toBeInTheDocument();
    expect(screen.getAllByText("مطلوب").length).toBeGreaterThan(0);
    expect(callsTo(fetchMock, "POST /api/dashboard/bundles")).toHaveLength(0);
  });

  it("creates the bundle and opens it", async () => {
    const fetchMock = stubPicker({
      "POST /api/dashboard/bundles": json(201, { data: bundleDashboardProduct({ id: 44 }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<BundleForm product={null} units={units} categories={categories} />);

    await user.type(screen.getByLabelText("اسم الباقة *"), "طقم الأول");
    await user.click(screen.getByLabelText("القسم *"));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "المرحلة الابتدائية" }));
    await user.type(screen.getByLabelText("كود الباقة *"), "SET-9");
    await user.selectOptions(screen.getByLabelText("وحدة بيع الباقة *"), "7");
    await pick(user, /ADW-SCI-1/);
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/dashboard/bundles/44"));
    expect(callsTo(fetchMock, "POST /api/dashboard/bundles")[0]?.body).toEqual({
      name: "طقم الأول",
      category_id: 1,
      description: null,
      code: "SET-9",
      unit_id: 7,
      moq: 1,
      components: [{ sku_unit_id: 5, quantity: 1 }],
      pricing: { mode: "percent_off_sum", value: "0" },
      status: "active",
    });
  });

  it("puts a taken code and a refused component on their fields", async () => {
    stubPicker({
      "PUT /api/dashboard/bundles/30": [
        envelopeError(409, "duplicate_code", "الكود مستخدم لمنتج آخر", { code: "SET-1" }),
        envelopeError(422, "validation_failed", "بعض البيانات غير صحيحة", {
          "components.1.sku_unit_id": ["هذا الصنف غير نشط"],
          custom_fields: ["قيمة غير صالحة"],
        }),
      ],
    });
    const user = userEvent.setup();
    renderWithQuery(<BundleForm product={bundleDashboardProduct()} units={units} categories={categories} />);

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);
    expect(await screen.findByText("الكود مستخدم لمنتج آخر")).toBeInTheDocument();

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);
    expect(await within(screen.getByTestId("component-21")).findByText("هذا الصنف غير نشط")).toBeInTheDocument();
    // A key without an input goes to the page alert.
    expect(screen.getByText("قيمة غير صالحة")).toBeInTheDocument();
  });

  it("shows each component's stock, what can be made now, and that an inactive bundle is hidden until activated", async () => {
    const user = userEvent.setup();
    stubApi({
      "GET /api/dashboard/products/30": json(200, { data: bundleDashboardProduct() }),
      "GET /api/dashboard/units": json(200, { data: units }),
      "GET /api/dashboard/categories": json(200, { data: categories }),
      "GET /api/dashboard/products": json(200, page([])),
      "PATCH /api/dashboard/products/30/status": json(200, {
        data: bundleDashboardProduct({ status: "active", updated_at: "2026-10-02T08:00:00Z" }),
      }),
    });
    renderWithQuery(<BundleEditor productId={30} />);

    expect(await screen.findByTestId("bundle-availability")).toHaveTextContent("متاح الآن: 3 باقة");
    expect(within(screen.getByTestId("component-5")).getByText("المخزون: 400")).toBeInTheDocument();
    expect(within(screen.getByTestId("component-21")).getByText("غير متاح للطلب")).toBeInTheDocument();
    expect(screen.getByTestId("bundle-sum")).toHaveTextContent("110.00 ج.م");
    expect(screen.getByTestId("bundle-price")).toHaveTextContent("99.00 ج.م");

    await user.click(within(screen.getByTestId("bundle-draft")).getByRole("button", { name: "تفعيل الباقة" }));
    await waitFor(() => expect(screen.queryByTestId("bundle-draft")).not.toBeInTheDocument());
  });
});

describe("GradeSetWizard", () => {
  const grade = selectField();

  it("generates a draft set from the list's facets and opens its editor", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/bundles/generate": json(201, { data: bundleDashboardProduct({ id: 45 }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(
      <GradeSetWizard
        query={{ "f[grade]": "11" }}
        categories={[{ node: category(), depth: 0, path: [] }]}
        facets={[grade]}
      />,
    );

    await user.click(screen.getByRole("button", { name: "إنشاء باقة صف دراسي" }));
    expect(screen.getByLabelText("الصف")).toHaveValue("11");
    await user.selectOptions(screen.getByLabelText("القسم"), "1");
    await user.click(screen.getByRole("button", { name: "إنشاء الباقة" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith("/dashboard/bundles/45"));
    expect(callsTo(fetchMock, "POST /api/dashboard/bundles/generate")[0]?.body).toEqual({
      filter: { category: 1, f: { grade: "11" } },
    });
  });

  it("asks for a selection first and shows why the server refused one", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/bundles/generate": envelopeError(422, "validation_failed", "بعض البيانات غير صحيحة", {
        filter: ["لا توجد منتجات نشطة مطابقة لهذا الاختيار"],
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<GradeSetWizard query={{}} categories={[]} facets={[grade]} />);

    await user.click(screen.getByRole("button", { name: "إنشاء باقة صف دراسي" }));
    await user.click(screen.getByRole("button", { name: "إنشاء الباقة" }));
    expect(await screen.findByText("اختر القسم أو قيمة واحدة على الأقل")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/bundles/generate")).toHaveLength(0);

    await user.selectOptions(screen.getByLabelText("الصف"), "12");
    await user.click(screen.getByRole("button", { name: "إنشاء الباقة" }));
    expect(await screen.findByText("لا توجد منتجات نشطة مطابقة لهذا الاختيار")).toBeInTheDocument();
    expect(router.push).not.toHaveBeenCalled();
  });
});

describe("ProductsPage bundles", () => {
  it("marks a bundle, opens it in the bundle editor and offers no duplicate for it", async () => {
    stubApi({
      "GET /api/dashboard/products": json(200, page([dashboardProduct(), bundleDashboardProduct()])),
      "GET /api/dashboard/categories": json(200, { data: [] }),
      "GET /api/dashboard/fields": json(200, { data: [] }),
    });
    renderWithQuery(<ProductsPage />, signedInAs("owner"));

    const row = await screen.findByTestId("product-30");
    expect(within(row).getByText("باقة")).toBeInTheDocument();
    expect(within(row).getByRole("link", { name: "طقم أولى إعدادي" })).toHaveAttribute("href", "/dashboard/bundles/30");
    expect(within(row).queryByRole("button", { name: "نسخ" })).not.toBeInTheDocument();
    expect(within(screen.getByTestId("product-3")).getByRole("button", { name: "نسخ" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "باقة جديدة" })).toHaveAttribute("href", "/dashboard/bundles/new");
    expect(screen.getByRole("button", { name: "إنشاء باقة صف دراسي" })).toBeInTheDocument();
  });
});
