import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { BulkPricingDialog } from "@/components/dashboard/catalog/BulkPricingDialog";
import { CategoriesPage } from "@/components/dashboard/catalog/CategoriesPage";
import { CustomFieldInputs } from "@/components/dashboard/catalog/CustomFieldInputs";
import { FieldDialog } from "@/components/dashboard/catalog/FieldsPage";
import { PriceInput } from "@/components/dashboard/catalog/PriceInput";
import { ProductForm } from "@/components/dashboard/catalog/ProductEditor";
import { ProductsPage } from "@/components/dashboard/catalog/ProductsPage";
import { UnitsPage } from "@/components/dashboard/catalog/UnitsPage";

import { category, dashboardProduct, selectField, unit } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery, router, searchParams } from "../../render";

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  vi.spyOn(toast, "error").mockImplementation(() => "t");
  router.replace.mockReset();
  router.push.mockReset();
  searchParams.value = new URLSearchParams();
});

describe("CustomFieldInputs", () => {
  const definitions = [
    selectField(),
    selectField({
      id: 2,
      key: "pages",
      label: "الصفحات",
      type: "number",
      is_required: false,
      sort_order: 2,
      options: [],
    }),
    selectField({
      id: 3,
      key: "color",
      label: "ملون",
      type: "boolean",
      is_required: false,
      sort_order: 3,
      options: [],
    }),
    selectField({ id: 4, key: "note", label: "ملاحظة", type: "text", is_required: false, sort_order: 4, options: [] }),
  ];

  it("renders each definition with its own input and marks required ones", () => {
    render(<CustomFieldInputs definitions={definitions} values={{}} onChange={vi.fn()} errors={{}} />);

    const grade = screen.getByLabelText("الصف *");
    expect(
      within(grade)
        .getAllByRole("option")
        .map((option) => option.textContent),
    ).toEqual(["اختر", "الأول", "الثاني"]);
    expect(screen.getByLabelText("الصفحات")).toHaveAttribute("type", "number");
    expect(
      within(screen.getByLabelText("ملون"))
        .getAllByRole("option")
        .map((option) => option.textContent),
    ).toEqual(["اختر", "نعم", "لا"]);
    expect(screen.getByLabelText("ملاحظة")).toHaveAttribute("type", "text");
  });

  it("keeps an archived option selectable only while it is the current value", () => {
    render(<CustomFieldInputs definitions={definitions} values={{ grade: "13" }} onChange={vi.fn()} errors={{}} />);

    expect(screen.getByRole("option", { name: "القديم" })).toBeInTheDocument();
  });

  it("reports changes by key and shows the field's error", () => {
    const onChange = vi.fn();
    render(
      <CustomFieldInputs
        definitions={definitions}
        values={{}}
        onChange={onChange}
        errors={{ grade: "هذا الحقل مطلوب" }}
      />,
    );

    fireEvent.change(screen.getByLabelText("الصف *"), { target: { value: "12" } });

    expect(onChange).toHaveBeenCalledWith("grade", "12");
    expect(screen.getByText("هذا الحقل مطلوب")).toBeInTheDocument();
  });
});

describe("PriceInput", () => {
  it("BR-PR10 shows the price a percentage gives and the discount a price implies", () => {
    const { rerender } = render(
      <PriceInput
        id="p"
        label="سعر الجملة"
        mode="percent"
        onModeChange={vi.fn()}
        value="25"
        onChange={vi.fn()}
        cover="10.10"
      />,
    );

    expect(screen.getByTestId("p-hint")).toHaveTextContent("= 7.58 ج.م");

    rerender(
      <PriceInput
        id="p"
        label="سعر الجملة"
        mode="price"
        onModeChange={vi.fn()}
        value="90"
        onChange={vi.fn()}
        cover="120.00"
      />,
    );

    expect(screen.getByTestId("p-hint")).toHaveTextContent("خصم 25.00% من سعر الغلاف");
  });

  it("disables the percentage without a cover price", () => {
    render(
      <PriceInput
        id="p"
        label="سعر الجملة"
        mode="price"
        onModeChange={vi.fn()}
        value="90"
        onChange={vi.fn()}
        cover={null}
      />,
    );

    expect(screen.getByRole("button", { name: "%" })).toBeDisabled();
  });
});

describe("ProductForm", () => {
  const units = [unit(), unit({ id: 2, name: "كرتونة" })];
  const categories = [category()];

  function renderForm(product = dashboardProduct()) {
    return renderWithQuery(
      <ProductForm product={product} definitions={[selectField()]} units={units} categories={categories} />,
    );
  }

  it("BR-PR04 refuses a duplicate tier threshold before calling the API", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderForm(dashboardProduct({ sku: { ...dashboardProduct().sku!, tiers: [] } }));

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    await user.click(screen.getByRole("button", { name: "إضافة شريحة" }));
    await user.click(screen.getByRole("button", { name: "إضافة شريحة" }));
    await user.type(screen.getByLabelText("من كمية 1"), "10");
    await user.type(screen.getByLabelText("السعر أو النسبة 1"), "30");
    await user.type(screen.getByLabelText("من كمية 2"), "10");
    await user.type(screen.getByLabelText("السعر أو النسبة 2"), "35");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("الحد الأدنى 10 مكرر في أكثر من شريحة")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")).toHaveLength(0);
  });

  it("saves the product as entered: the base price as a percentage and the custom field as an option id", async () => {
    const saved = dashboardProduct({ updated_at: "2026-10-02T08:00:00Z" });
    const fetchMock = stubApi({ "PUT /api/dashboard/products/3": json(200, { data: saved }) });
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")).toHaveLength(1));
    const body = callsTo(fetchMock, "PUT /api/dashboard/products/3")[0]?.body;
    expect(body).toMatchObject({
      name: "الأضواء علوم",
      category_id: 1,
      custom_fields: { grade: 11 },
      sku: {
        code: "ADW-SCI-1",
        base_price_percent: "25.00",
        base_price: null,
        moq: 5,
        tiers: [{ min_quantity: 10, discount_percent: "30.00" }],
      },
    });
    expect(body).not.toHaveProperty("status");
    expect(toast.success).toHaveBeenCalledWith("تم حفظ المنتج");
  });

  it("puts the backend's field errors on the fields and opens their tab", async () => {
    stubApi({
      "PUT /api/dashboard/products/3": envelopeError(422, "validation_failed", "بعض البيانات غير صحيحة", {
        "custom_fields.grade": ["الاختيار غير موجود في هذا الحقل"],
      }),
    });
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("الاختيار غير موجود في هذا الحقل")).toBeVisible();
    expect(screen.getByRole("tab", { name: "الحقول" })).toHaveAttribute("aria-selected", "true");
  });

  it("lists the backend's invalid_tiers problems on the pricing tab", async () => {
    stubApi({
      "PUT /api/dashboard/products/3": envelopeError(422, "invalid_tiers", "شرائح الأسعار غير صحيحة", {
        tiers: ["سعر شريحة 10 أعلى من السعر الأساسي"],
      }),
    });
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("سعر شريحة 10 أعلى من السعر الأساسي")).toBeVisible();
  });

  it("creates a product with status and opening stock, then opens it", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/products": json(201, { data: dashboardProduct({ id: 9 }) }) });
    const user = userEvent.setup();
    renderWithQuery(<ProductForm product={null} definitions={[]} units={units} categories={categories} />);

    await user.type(screen.getByLabelText("اسم المنتج"), "كتاب جديد");
    await user.selectOptions(screen.getByLabelText("القسم"), "1");
    await user.type(screen.getByLabelText("كود الصنف (SKU)"), "NEW-1");
    await user.selectOptions(screen.getByLabelText("وحدة البيع"), "2");
    await user.selectOptions(screen.getByLabelText("الوحدة الأساسية"), "1");
    await user.clear(screen.getByLabelText("عدد الوحدات الأساسية في وحدة البيع"));
    await user.type(screen.getByLabelText("عدد الوحدات الأساسية في وحدة البيع"), "20");
    await user.type(screen.getByLabelText("الرصيد الافتتاحي (بالوحدة الأساسية)"), "200");
    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    await user.type(screen.getByLabelText("سعر الجملة"), "50.00");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/dashboard/products/9"));
    expect(callsTo(fetchMock, "POST /api/dashboard/products")[0]?.body).toMatchObject({
      status: "active",
      opening_stock: 200,
      sku: {
        code: "NEW-1",
        selling_unit_id: 2,
        base_unit_id: 1,
        conversion_factor: 20,
        base_price: "50.00",
        cover_price: null,
      },
    });
  });
});

describe("CategoriesPage", () => {
  const tree = [
    category({ id: 1, children: [category({ id: 3, name: "رابعة ابتدائي", parent_id: 1 })] }),
    category({ id: 2, name: "المرحلة الإعدادية", sort_order: 1 }),
  ];

  it("hides a category's own subtree from its parent picker", async () => {
    stubApi({ "GET /api/dashboard/categories": json(200, { data: tree }) });
    const user = userEvent.setup();
    renderWithQuery(<CategoriesPage />);

    const row = await screen.findByTestId("category-1");
    await user.click(within(row).getAllByRole("button", { name: "تعديل" })[0]!);

    const options = within(screen.getByLabelText("القسم الأب"))
      .getAllByRole("option")
      .map((option) => option.textContent);
    expect(options).toEqual(["بدون (قسم رئيسي)", "المرحلة الإعدادية"]);
  });

  it("moves a category and surfaces the backend's category_cycle message", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/categories": json(200, { data: tree }),
      "PUT /api/dashboard/categories/2": envelopeError(
        422,
        "category_cycle",
        "لا يمكن نقل القسم تحت أحد أقسامه الفرعية",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<CategoriesPage />);

    await user.click(within(await screen.findByTestId("category-2")).getByRole("button", { name: "تعديل" }));
    await user.selectOptions(screen.getByLabelText("القسم الأب"), "3");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("لا يمكن نقل القسم تحت أحد أقسامه الفرعية");
    expect(callsTo(fetchMock, "PUT /api/dashboard/categories/2")[0]?.body).toEqual({
      name: "المرحلة الإعدادية",
      parent_id: 3,
    });
  });

  it("archives a category and hides archived ones unless asked", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/categories": json(200, {
        data: [...tree, category({ id: 4, name: "مؤرشف", status: "archived" })],
      }),
      "POST /api/dashboard/categories/2/archive": json(200, { data: category({ id: 2, status: "archived" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CategoriesPage />);

    await user.click(within(await screen.findByTestId("category-2")).getByRole("button", { name: "أرشفة" }));

    expect(callsTo(fetchMock, "POST /api/dashboard/categories/2/archive")).toHaveLength(1);
    expect(screen.queryByText("مؤرشف")).not.toBeInTheDocument();
    await user.click(screen.getByLabelText("عرض الأقسام المؤرشفة"));
    expect(router.replace).toHaveBeenCalledWith("/?archived=1", { scroll: false });
  });
});

describe("UnitsPage", () => {
  it("shows why a unit in use cannot be archived", async () => {
    stubApi({
      "GET /api/dashboard/units": json(200, { data: [unit()] }),
      "POST /api/dashboard/units/1/archive": envelopeError(409, "in_use", "الوحدة مستخدمة في أصناف ولا يمكن أرشفتها"),
    });
    const user = userEvent.setup();
    renderWithQuery(<UnitsPage />);

    await user.click(await screen.findByRole("button", { name: "أرشفة" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("الوحدة مستخدمة في أصناف");
  });
});

describe("FieldDialog", () => {
  it("creates a select field with its options, one per line", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/fields": json(201, { data: selectField() }) });
    const user = userEvent.setup();
    const onClose = vi.fn();
    renderWithQuery(<FieldDialog field={null} onClose={onClose} />);

    await user.type(screen.getByLabelText("الاسم الظاهر"), "الصف");
    await user.type(screen.getByLabelText("المفتاح (بالإنجليزية)"), "grade");
    await user.type(screen.getByLabelText("الاختيارات"), "الأول{enter}الثاني{enter}");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(callsTo(fetchMock, "POST /api/dashboard/fields")[0]?.body).toMatchObject({
      key: "grade",
      label: "الصف",
      type: "select",
      options: ["الأول", "الثاني"],
    });
  });

  it("warns that the type locks once used and shows field_type_locked from the backend", async () => {
    stubApi({
      "PUT /api/dashboard/fields/1": envelopeError(409, "field_type_locked", "لا يمكن تغيير نوع حقل له قيم في منتجات"),
    });
    const user = userEvent.setup();
    renderWithQuery(<FieldDialog field={selectField()} onClose={vi.fn()} />);

    expect(screen.getByText("لا يمكن تغيير النوع بعد استخدام الحقل في منتجات.")).toBeInTheDocument();
    expect(screen.getByLabelText("المفتاح (بالإنجليزية)")).toBeDisabled();
    await user.selectOptions(screen.getByLabelText("النوع"), "text");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByText("لا يمكن تغيير نوع حقل له قيم في منتجات")).toBeInTheDocument();
  });
});

describe("ProductsPage", () => {
  it("BR-P12 offers bulk actions only for a filtered list", async () => {
    stubApi({
      "GET /api/dashboard/products": json(200, page([dashboardProduct()])),
      "GET /api/dashboard/categories": json(200, { data: [] }),
      "GET /api/dashboard/fields": json(200, { data: [] }),
    });

    renderWithQuery(<ProductsPage />);

    expect(await screen.findByTestId("bulk-blocked")).toHaveTextContent("اختر تصفية أولًا");
    expect(await screen.findByRole("link", { name: "الأضواء علوم" })).toHaveAttribute("href", "/dashboard/products/3");
  });

  it("duplicates a product and opens the copy", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/products": json(200, page([dashboardProduct()])),
      "GET /api/dashboard/categories": json(200, { data: [] }),
      "GET /api/dashboard/fields": json(200, { data: [] }),
      "POST /api/dashboard/products/3/duplicate": json(201, { data: dashboardProduct({ id: 8, status: "inactive" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<ProductsPage />);

    await user.click(await screen.findByRole("button", { name: "نسخ" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith("/dashboard/products/8"));
    expect(callsTo(fetchMock, "POST /api/dashboard/products/3/duplicate")).toHaveLength(1);
  });
});

describe("BulkPricingDialog", () => {
  it("BR-PR11 previews affected and skipped products before applying the same request", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/pricing/bulk/preview": json(200, {
        data: {
          affected: 12,
          skipped_no_cover_price: [{ product_id: 4, sku_id: 7, code: "NO-COVER", name: "دراسات" }],
        },
      }),
      "POST /api/dashboard/pricing/bulk": json(200, {
        data: { affected: 12, skipped_no_cover_price: [], batch_id: "b" },
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<BulkPricingDialog filter={{ q: "علوم" }} />);

    await user.click(screen.getByRole("button", { name: "تسعير جماعي" }));
    await user.type(screen.getByLabelText("خصم السعر الأساسي %"), "25");
    expect(screen.getByRole("button", { name: "تطبيق" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "معاينة" }));

    const preview = await screen.findByTestId("bulk-preview");
    expect(preview).toHaveTextContent("سيتم تحديث 12 منتج");
    expect(preview).toHaveTextContent("دراسات");
    await user.click(screen.getByRole("button", { name: "تطبيق" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم تحديث أسعار 12 منتج"));
    expect(callsTo(fetchMock, "POST /api/dashboard/pricing/bulk")[0]?.body).toEqual({
      filter: { q: "علوم" },
      base_price_percent: "25",
      tiers: [],
    });
  });

  it("needs a new preview after the inputs change", async () => {
    stubApi({
      "POST /api/dashboard/pricing/bulk/preview": json(200, { data: { affected: 3, skipped_no_cover_price: [] } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<BulkPricingDialog filter={{ q: "علوم" }} />);

    await user.click(screen.getByRole("button", { name: "تسعير جماعي" }));
    await user.type(screen.getByLabelText("خصم السعر الأساسي %"), "25");
    await user.click(screen.getByRole("button", { name: "معاينة" }));
    await screen.findByTestId("bulk-preview");
    await user.type(screen.getByLabelText("خصم السعر الأساسي %"), "5");

    expect(screen.getByRole("button", { name: "تطبيق" })).toBeDisabled();
  });
});
