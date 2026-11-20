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

import { category, dashboardProduct, dashboardSku, selectField, unit, variantProduct } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery, router, searchParams } from "../../render";

/** Opens the product's category combobox and picks a category by name. */
async function pickCategory(user: ReturnType<typeof userEvent.setup>, name: string) {
  await user.click(screen.getByLabelText("القسم *"));
  await user.click(await screen.findByRole("option", { name }));
}

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

  it("renders each definition with its own input and marks required and optional ones", () => {
    render(<CustomFieldInputs definitions={definitions} values={{}} kind="general" onChange={vi.fn()} errors={{}} />);

    const grade = screen.getByLabelText("الصف *");
    expect(
      within(grade)
        .getAllByRole("option")
        .map((option) => option.textContent),
    ).toEqual(["اختر", "الأول", "الثاني"]);
    expect(screen.getByLabelText("الصفحات (اختياري)")).toHaveAttribute("type", "number");
    expect(
      within(screen.getByLabelText("ملون (اختياري)"))
        .getAllByRole("option")
        .map((option) => option.textContent),
    ).toEqual(["اختر", "نعم", "لا"]);
    expect(screen.getByLabelText("ملاحظة (اختياري)")).toHaveAttribute("type", "text");
  });

  it("keeps an archived option selectable only while it is the current value", () => {
    render(
      <CustomFieldInputs
        definitions={definitions}
        values={{ grade: "13" }}
        kind="general"
        onChange={vi.fn()}
        errors={{}}
      />,
    );

    expect(screen.getByRole("option", { name: "القديم" })).toBeInTheDocument();
  });

  it("reports changes by key and shows the field's error", () => {
    const onChange = vi.fn();
    render(
      <CustomFieldInputs
        definitions={definitions}
        values={{}}
        kind="general"
        onChange={onChange}
        errors={{ grade: "هذا الحقل مطلوب" }}
      />,
    );

    fireEvent.change(screen.getByLabelText("الصف *"), { target: { value: "12" } });

    expect(onChange).toHaveBeenCalledWith("grade", "12");
    expect(screen.getByText("هذا الحقل مطلوب")).toBeInTheDocument();
  });

  it("D69 shows only the fields of the product's section, and a hint until a category is chosen", () => {
    const sectioned = [
      selectField({ kind: "books" }),
      selectField({ id: 5, key: "age", label: "العمر", type: "text", kind: "toys", options: [] }),
    ];
    const { rerender } = render(
      <CustomFieldInputs definitions={sectioned} values={{}} kind="toys" onChange={vi.fn()} errors={{}} />,
    );

    expect(screen.getByLabelText("العمر *")).toBeInTheDocument();
    expect(screen.queryByLabelText("الصف *")).not.toBeInTheDocument();

    rerender(<CustomFieldInputs definitions={sectioned} values={{}} kind={null} onChange={vi.fn()} errors={{}} />);

    expect(screen.getByText("الحقول تتبع قسم المنتج: اختر القسم أولًا لتظهر حقوله.")).toBeInTheDocument();
    expect(screen.queryByLabelText("العمر *")).not.toBeInTheDocument();
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
        referenceLabel="سعر الغلاف"
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
        referenceLabel="سعر الغلاف"
      />,
    );

    expect(screen.getByTestId("p-hint")).toHaveTextContent("خصم 25.00% من سعر الغلاف");

    rerender(
      <PriceInput
        id="p"
        label="سعر الجملة"
        mode="price"
        onModeChange={vi.fn()}
        value="90"
        onChange={vi.fn()}
        cover="120.00"
        referenceLabel="سعر القطاعي"
      />,
    );

    expect(screen.getByTestId("p-hint")).toHaveTextContent("خصم 25.00% من سعر القطاعي");
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
        referenceLabel="سعر الغلاف"
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
    renderForm(dashboardProduct({ sku: dashboardSku({ tiers: [] }) }));

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

  it("shows placeholders on a tier's quantity and value, the value one following the price mode", async () => {
    stubApi({});
    const user = userEvent.setup();
    renderForm(dashboardProduct({ sku: dashboardSku({ tiers: [] }) }));

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    await user.click(screen.getByRole("button", { name: "إضافة شريحة" }));
    const tier = within(screen.getByTestId("tier-0"));
    const value = tier.getByLabelText("السعر أو النسبة 1");

    expect(tier.getByLabelText("من كمية 1")).toHaveAttribute("placeholder", "من كمية");
    await user.selectOptions(tier.getByLabelText("طريقة إدخال السعر"), "price");
    expect(value).toHaveAttribute("placeholder", "السعر");
    await user.selectOptions(tier.getByLabelText("طريقة إدخال السعر"), "percent");
    expect(value).toHaveAttribute("placeholder", "النسبة %");
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
        base_unit_id: 1,
        units: [
          {
            id: 5,
            unit_id: 2,
            conversion_factor: 20,
            base_price_percent: "25.00",
            base_price: null,
            moq: 5,
            tiers: [{ min_quantity: 10, discount_percent: "30.00" }],
            is_default: true,
          },
        ],
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
        "sku.units.0.tiers": ["سعر شريحة 10 أعلى من السعر الأساسي"],
      }),
    });
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("سعر شريحة 10 أعلى من السعر الأساسي")).toBeVisible();
  });

  it("marks the required fields with * and the optional ones, with a legend", async () => {
    const user = userEvent.setup();
    renderWithQuery(<ProductForm product={null} definitions={[]} units={units} categories={categories} />);

    expect(screen.getByText("الحقول المعلَّمة بعلامة * إجبارية.")).toBeInTheDocument();
    for (const label of ["اسم المنتج *", "القسم *", "كود الصنف (SKU) *", "وحدة البيع *", "الوحدة الأساسية *"]) {
      expect(screen.getByLabelText(label)).toBeInTheDocument();
    }
    expect(screen.getByLabelText("الوصف (اختياري)")).toBeInTheDocument();

    await user.click(screen.getByRole("tab", { name: "التسعير" }));

    expect(screen.getByLabelText("سعر الغلاف / القطاعي (اختياري)")).toBeInTheDocument();
    expect(screen.getByLabelText("سعر الجملة *")).toBeInTheDocument();
    expect(screen.getByLabelText("الحد الأدنى للطلب (بوحدة البيع) *")).toBeInTheDocument();
    expect(screen.getByText("شرائح الكمية").closest("legend")).toHaveTextContent("شرائح الكمية (اختياري)");
  });

  it("D68 names the reference price after the chosen category's section and shows that section's fields", async () => {
    const user = userEvent.setup();
    const sectioned = [
      selectField({ kind: "books" }),
      selectField({ id: 5, key: "age", label: "العمر", type: "text", kind: "toys", is_required: false, options: [] }),
    ];
    renderWithQuery(
      <ProductForm
        product={null}
        definitions={sectioned}
        units={units}
        categories={[category(), category({ id: 2, name: "ألعاب", kind: "toys" })]}
      />,
    );

    await pickCategory(user, "المرحلة الابتدائية");
    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    expect(screen.getByLabelText("سعر الغلاف (اختياري)")).toBeInTheDocument();

    await user.click(screen.getByRole("tab", { name: "البيانات" }));
    await pickCategory(user, "ألعاب");
    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    expect(screen.getByLabelText("سعر القطاعي (اختياري)")).toBeInTheDocument();

    await user.click(screen.getByRole("tab", { name: "الحقول" }));
    expect(screen.getByLabelText("العمر (اختياري)")).toBeInTheDocument();
    expect(screen.queryByLabelText("الصف *")).not.toBeInTheDocument();
  });

  it("searches the category picker, showing each match with its parent path", async () => {
    const user = userEvent.setup();
    const tree = [
      category({
        children: [category({ id: 3, name: "رابعة ابتدائي", parent_id: 1 })],
      }),
      category({ id: 2, name: "ألعاب", kind: "toys", sort_order: 1 }),
      category({ id: 4, name: "قديم", sort_order: 2, status: "archived" }),
    ];
    renderWithQuery(<ProductForm product={null} definitions={[]} units={units} categories={tree} />);

    await user.click(screen.getByLabelText("القسم *"));
    expect(
      within(screen.getByRole("listbox"))
        .getAllByRole("option")
        .map((option) => option.textContent),
    ).toEqual(["المرحلة الابتدائية", "رابعة ابتدائي", "ألعاب"]);

    await user.type(screen.getByPlaceholderText("ابحث باسم القسم"), "رابعه");
    expect(
      within(screen.getByRole("listbox"))
        .getAllByRole("option")
        .map((option) => option.textContent),
    ).toEqual(["رابعة ابتدائيالمرحلة الابتدائية"]);

    await user.clear(screen.getByPlaceholderText("ابحث باسم القسم"));
    await user.type(screen.getByPlaceholderText("ابحث باسم القسم"), "زzz");
    expect(screen.getByText("لا يوجد قسم بهذا الاسم")).toBeInTheDocument();

    await user.clear(screen.getByPlaceholderText("ابحث باسم القسم"));
    await user.click(screen.getByRole("option", { name: "رابعة ابتدائي" }));
    expect(screen.getByLabelText("القسم *")).toHaveTextContent("المرحلة الابتدائية ‹رابعة ابتدائي");
  });

  it("creates a product with status and opening stock, then opens it", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/products": json(201, { data: dashboardProduct({ id: 9 }) }) });
    const user = userEvent.setup();
    renderWithQuery(<ProductForm product={null} definitions={[]} units={units} categories={categories} />);

    await user.type(screen.getByLabelText("اسم المنتج *"), "كتاب جديد");
    await pickCategory(user, "المرحلة الابتدائية");
    await user.type(screen.getByLabelText("كود الصنف (SKU) *"), "NEW-1");
    await user.selectOptions(screen.getByLabelText("الوحدة الأساسية *"), "1");
    await user.type(screen.getByLabelText("الرصيد الافتتاحي (بالوحدة الأساسية) (اختياري)"), "200");
    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    await user.selectOptions(screen.getByLabelText("وحدة البيع *"), "2");
    await user.clear(screen.getByLabelText("عدد الوحدات الأساسية في وحدة البيع *"));
    await user.type(screen.getByLabelText("عدد الوحدات الأساسية في وحدة البيع *"), "20");
    await user.type(screen.getByLabelText("سعر الجملة *"), "50.00");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/dashboard/products/9"));
    expect(callsTo(fetchMock, "POST /api/dashboard/products")[0]?.body).toMatchObject({
      status: "active",
      opening_stock: 200,
      sku: {
        code: "NEW-1",
        base_unit_id: 1,
        units: [{ unit_id: 2, conversion_factor: 20, base_price: "50.00", cover_price: null, is_default: true }],
      },
    });
  });

  it("D91 sells a SKU in a second unit with its own price and makes it the default", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/products/3": json(200, { data: dashboardProduct() }) });
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    expect(screen.getByText("وحدات البيع")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "إضافة وحدة" }));
    const second = within(screen.getByTestId("sku-unit-1"));
    await user.selectOptions(second.getByLabelText("وحدة البيع *"), "1");
    await user.clear(second.getByLabelText("عدد الوحدات الأساسية في وحدة البيع *"));
    await user.type(second.getByLabelText("عدد الوحدات الأساسية في وحدة البيع *"), "12");
    await user.type(second.getByLabelText("سعر الجملة *"), "1000");
    await user.click(second.getByRole("radio", { name: /الوحدة الافتراضية/ }));
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")).toHaveLength(1));
    const body = callsTo(fetchMock, "PUT /api/dashboard/products/3")[0]?.body as { sku: { units: unknown[] } };
    expect(body.sku.units).toEqual([
      expect.objectContaining({ id: 5, unit_id: 2, is_default: false }),
      expect.objectContaining({ unit_id: 1, conversion_factor: 12, base_price: "1000", moq: 1, is_default: true }),
    ]);
    expect(body.sku.units[1]).not.toHaveProperty("id");
  });

  it("D91 refuses the same unit twice before calling the API, and removes a unit", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    await user.click(screen.getByRole("button", { name: "إضافة وحدة" }));
    const second = within(screen.getByTestId("sku-unit-1"));
    await user.selectOptions(second.getByLabelText("وحدة البيع *"), "2");
    await user.type(second.getByLabelText("سعر الجملة *"), "1000");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("هذه الوحدة مكررة")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")).toHaveLength(0);

    await user.click(second.getByRole("button", { name: "حذف الوحدة" }));
    expect(screen.queryByTestId("sku-unit-1")).toBeNull();
  });

  it("D91 shows a unit's server error on that unit", async () => {
    stubApi({
      "PUT /api/dashboard/products/3": envelopeError(422, "validation_failed", "بعض البيانات غير صحيحة", {
        "sku.units.0.unit_id": ["العنصر المختار مؤرشف"],
      }),
    });
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("العنصر المختار مؤرشف")).toBeVisible();
    expect(screen.getByRole("tab", { name: "التسعير" })).toHaveAttribute("aria-selected", "true");
  });
});

describe("ProductForm with options (V2 Task 14)", () => {
  const units = [unit(), unit({ id: 2, name: "كرتونة" })];
  const categories = [category()];

  function renderForm(product = dashboardProduct()) {
    return renderWithQuery(
      <ProductForm product={product} definitions={[selectField()]} units={units} categories={categories} />,
    );
  }

  const rows = () => screen.getAllByTestId(/^variant-\d$/);

  async function addValue(user: ReturnType<typeof userEvent.setup>, option: string, value: string) {
    await user.type(screen.getByLabelText(`قيمة جديدة لـ ${option}`), `${value}{Enter}`);
  }

  it("BR-P14 adding an option generates a variant per value and saves options + skus instead of sku", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/products/3": json(200, { data: dashboardProduct() }) });
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    expect(screen.getByTestId("sku-unit-0")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "إضافة خيار" }));
    await user.type(screen.getByLabelText("اسم الخيار 1 *"), "اللون");
    await addValue(user, "اللون", "أزرق");
    await addValue(user, "اللون", "Navy blue");

    expect(screen.queryByTestId("sku-unit-0")).toBeNull();
    expect(within(screen.getByTestId("variant-0")).getByLabelText("الكود *")).toHaveValue("ADW-SCI-1-1");
    expect(within(screen.getByTestId("variant-1")).getByLabelText("الكود *")).toHaveValue("ADW-SCI-1-NAVY-BLUE");
    expect(screen.getByTestId("variant-1-price")).toHaveTextContent("كرتونة · 25.00%");

    await user.selectOptions(within(screen.getByTestId("variant-1")).getByLabelText("الحالة"), "inactive");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")).toHaveLength(1));
    const body = callsTo(fetchMock, "PUT /api/dashboard/products/3")[0]?.body as Record<string, unknown>;
    expect(body).not.toHaveProperty("sku");
    expect(body.options).toEqual([
      {
        name: "اللون",
        values: [
          { value: "أزرق", image_id: null },
          { value: "Navy blue", image_id: null },
        ],
      },
    ]);
    expect(body.skus).toEqual([
      expect.objectContaining({ code: "ADW-SCI-1-1", base_unit_id: 1, status: "active", option_values: ["أزرق"] }),
      expect.objectContaining({ code: "ADW-SCI-1-NAVY-BLUE", status: "inactive", option_values: ["Navy blue"] }),
    ]);
    const firstUnit = (body.skus as { units: object[] }[])[0]?.units[0];
    expect(firstUnit).toMatchObject({ unit_id: 2, base_price_percent: "25.00", is_default: true });
    expect(firstUnit).not.toHaveProperty("id");
  });

  it("a second option multiplies the rows, and removing a value drops its rows", async () => {
    stubApi({});
    const user = userEvent.setup();
    renderForm(variantProduct());

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    expect(rows()).toHaveLength(2);
    await user.click(screen.getByRole("button", { name: "إضافة خيار" }));
    await user.type(screen.getByLabelText("اسم الخيار 2 *"), "المقاس");
    await addValue(user, "المقاس", "S");
    await addValue(user, "المقاس", "M");

    expect(rows().map((row) => row.getAttribute("aria-label"))).toEqual([
      "أزرق / S",
      "أزرق / M",
      "أسود / S",
      "أسود / M",
    ]);
    expect(within(screen.getByTestId("variant-3")).getByLabelText("الكود *")).toHaveValue("PEN-01-2-M");

    await user.click(screen.getByRole("button", { name: "حذف أسود" }));
    expect(rows()).toHaveLength(2);
    // A combination this save creates takes an opening stock.
    expect(within(screen.getByTestId("variant-0")).getByLabelText("الرصيد الافتتاحي (اختياري)")).toBeInTheDocument();
  });

  it("refuses a repeated value as it is typed", async () => {
    stubApi({});
    const user = userEvent.setup();
    renderForm(variantProduct());

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    await addValue(user, "اللون", "أزرق");

    expect(screen.getByText("هذه القيمة موجودة بالفعل")).toBeInTheDocument();
    expect(rows()).toHaveLength(2);
  });

  it("«تطبيق على الكل» copies the first variant's units and prices to every variant", async () => {
    const product = variantProduct();
    const black = {
      ...product.skus[1]!,
      ...dashboardSku({ id: 22, code: "PEN-01-2", base_price: "50.00", base_price_percent: null, tiers: [] }),
    };
    const fetchMock = stubApi({ "PUT /api/dashboard/products/4": json(200, { data: product }) });
    const user = userEvent.setup();
    renderForm({ ...product, skus: [product.skus[0]!, black] });

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    expect(screen.getByTestId("variant-1-price")).toHaveTextContent("كرتونة · 50.00 ج.م");
    await user.click(screen.getByRole("button", { name: "تطبيق على الكل" }));
    expect(screen.getByTestId("variant-1-price")).toHaveTextContent("كرتونة · 25.00%");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/products/4")).toHaveLength(1));
    const body = callsTo(fetchMock, "PUT /api/dashboard/products/4")[0]?.body as { skus: { units: object[] }[] };
    expect(body.skus[1]?.units).toEqual([
      expect.objectContaining({ id: 22, unit_id: 2, base_price: null, base_price_percent: "25.00" }),
    ]);
  });

  it("opens a variant's units editor and checks its prices before calling the API", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderForm(variantProduct());

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    await user.click(within(screen.getByTestId("variant-0")).getByRole("button", { name: "الوحدات والأسعار" }));
    const unitCard = within(screen.getByTestId("variant-0-unit-0"));
    await user.clear(unitCard.getByLabelText("سعر الجملة *"));
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("راجع وحدات وأسعار هذا المتغير")).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "التسعير" })).toHaveAttribute("aria-selected", "true");
    expect(callsTo(fetchMock, "PUT /api/dashboard/products/4")).toHaveLength(0);
  });

  it("needs one active variant", async () => {
    const fetchMock = stubApi({});
    const user = userEvent.setup();
    renderForm(variantProduct());

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    await user.selectOptions(within(screen.getByTestId("variant-0")).getByLabelText("الحالة"), "inactive");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("اجعل متغيرًا واحدًا على الأقل نشطًا")).toBeInTheDocument();
    expect(callsTo(fetchMock, "PUT /api/dashboard/products/4")).toHaveLength(0);
  });

  it("shows the backend's skus.* and options.* errors on the variant and the option", async () => {
    stubApi({
      "PUT /api/dashboard/products/4": envelopeError(422, "validation_failed", "بعض البيانات غير صحيحة", {
        "skus.1.code": ["الكود مستخدم بالفعل"],
        "options.0.values.1.value": ["القيمة طويلة"],
      }),
    });
    const user = userEvent.setup();
    renderForm(variantProduct());

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    expect(await screen.findByText("الكود مستخدم بالفعل")).toBeVisible();
    expect(within(screen.getByTestId("variant-1")).getByText("الكود مستخدم بالفعل")).toBeInTheDocument();
    expect(within(screen.getByTestId("product-option-0")).getByText("القيمة طويلة")).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "التسعير" })).toHaveAttribute("aria-selected", "true");
  });

  it("offers the product's images as a value's swatch", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/products/4": json(200, { data: variantProduct() }) });
    const user = userEvent.setup();
    renderForm(variantProduct());

    await user.click(screen.getByRole("tab", { name: "التسعير" }));
    expect(screen.getByLabelText("صورة أسود")).toHaveValue("7");
    await user.selectOptions(screen.getByLabelText("صورة أزرق"), "7");
    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/products/4")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/products/4")[0]?.body).toMatchObject({
      options: [
        {
          id: 31,
          values: [
            { id: 41, image_id: 7 },
            { id: 42, image_id: 7 },
          ],
        },
      ],
    });
  });

  it("keeps the V1 sku body for a product without options", async () => {
    const fetchMock = stubApi({ "PUT /api/dashboard/products/3": json(200, { data: dashboardProduct() }) });
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getAllByRole("button", { name: "حفظ" })[0]!);

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/products/3")).toHaveLength(1));
    const body = callsTo(fetchMock, "PUT /api/dashboard/products/3")[0]?.body;
    expect(body).toHaveProperty("sku");
    expect(body).not.toHaveProperty("options");
    expect(body).not.toHaveProperty("skus");
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

  it("D68 a root category gets a section kind, sent with the body; a child inherits and sends none", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/categories": json(200, { data: tree }),
      "POST /api/dashboard/categories": json(201, { data: category({ id: 9, kind: "toys" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CategoriesPage />);

    expect(await screen.findAllByText("كتب خارجية")).toHaveLength(2);
    await user.click(screen.getByRole("button", { name: "إضافة قسم" }));
    await user.type(screen.getByLabelText("اسم القسم"), "ألعاب");
    await user.selectOptions(screen.getByLabelText("نوع القسم"), "toys");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(callsTo(fetchMock, "POST /api/dashboard/categories")).toHaveLength(1));
    expect(callsTo(fetchMock, "POST /api/dashboard/categories")[0]?.body).toEqual({
      name: "ألعاب",
      parent_id: null,
      kind: "toys",
      icon: null,
    });

    await user.click(screen.getByRole("button", { name: "إضافة قسم" }));
    await user.selectOptions(screen.getByLabelText("القسم الأب"), "1");

    expect(screen.queryByLabelText("نوع القسم")).not.toBeInTheDocument();
    expect(screen.queryByRole("radiogroup", { name: "أيقونة القسم" })).not.toBeInTheDocument();
    expect(screen.getByText("يرث نوع القسم الرئيسي: كتب خارجية")).toBeInTheDocument();
  });

  it("D73 a root category gets the icon picked for its store tile", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/categories": json(200, { data: tree }),
      "POST /api/dashboard/categories": json(201, { data: category({ id: 9, icon: "backpack" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CategoriesPage />);

    await user.click(await screen.findByRole("button", { name: "إضافة قسم" }));
    await user.type(screen.getByLabelText("اسم القسم"), "شنط مدرسية");

    const picker = screen.getByRole("radiogroup", { name: "أيقونة القسم" });
    expect(within(picker).getByRole("radio", { name: "تلقائي" })).toHaveAttribute("aria-checked", "true");
    // "automatic" previews the icon the store will guess from the name.
    expect(within(picker).getByRole("radio", { name: "تلقائي" }).querySelector("[data-icon]")).toHaveAttribute(
      "data-icon",
      "backpack",
    );

    await user.click(within(picker).getByRole("radio", { name: "حافظات" }));
    expect(within(picker).getByRole("radio", { name: "حافظات" })).toHaveAttribute("aria-checked", "true");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(callsTo(fetchMock, "POST /api/dashboard/categories")).toHaveLength(1));
    expect(callsTo(fetchMock, "POST /api/dashboard/categories")[0]?.body).toMatchObject({ icon: "folder" });
  });

  it("D73 editing a root shows its icon and can set it back to automatic", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/categories": json(200, { data: [category({ id: 2, name: "ألعاب", icon: "puzzle" })] }),
      "PUT /api/dashboard/categories/2": json(200, { data: category({ id: 2, name: "ألعاب" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CategoriesPage />);

    const row = await screen.findByTestId("category-2");
    expect(row.querySelector("[data-icon]")).toHaveAttribute("data-icon", "puzzle");

    await user.click(within(row).getByRole("button", { name: "تعديل" }));
    expect(screen.getByRole("radio", { name: "بازل" })).toHaveAttribute("aria-checked", "true");
    await user.click(screen.getByRole("radio", { name: "تلقائي" }));
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(callsTo(fetchMock, "PUT /api/dashboard/categories/2")).toHaveLength(1));
    expect(callsTo(fetchMock, "PUT /api/dashboard/categories/2")[0]?.body).toEqual({
      name: "ألعاب",
      parent_id: null,
      kind: "books",
      icon: null,
    });
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
    await user.click(screen.getByRole("button", { name: /^تصفية/ }));
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
  it("creates a select field with its options, one input per option", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/fields": json(201, { data: selectField() }) });
    const user = userEvent.setup();
    const onClose = vi.fn();
    renderWithQuery(<FieldDialog field={null} onClose={onClose} />);

    await user.type(screen.getByLabelText("الاسم الظاهر"), "الصف");
    await user.type(screen.getByLabelText("المفتاح (بالإنجليزية)"), "grade");
    await user.type(screen.getByLabelText("اسم الاختيار 1"), "الأول");
    await user.click(screen.getByRole("button", { name: "إضافة اختيار" }));
    await user.type(screen.getByLabelText("اسم الاختيار 2"), "الثاني{enter}");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(callsTo(fetchMock, "POST /api/dashboard/fields")[0]?.body).toMatchObject({
      key: "grade",
      label: "الصف",
      type: "select",
      kind: null,
      options: ["الأول", "الثاني"],
    });
  });

  it("adds a row on enter and drops a removed option", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/fields": json(201, { data: selectField() }) });
    const user = userEvent.setup();
    renderWithQuery(<FieldDialog field={null} onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("الاسم الظاهر"), "الصف");
    await user.type(screen.getByLabelText("المفتاح (بالإنجليزية)"), "grade");
    await user.type(screen.getByLabelText("اسم الاختيار 1"), "الأول{enter}");
    await user.type(screen.getByLabelText("اسم الاختيار 2"), "الثاني{enter}");
    await user.type(screen.getByLabelText("اسم الاختيار 3"), "الثالث");
    await user.click(screen.getByRole("button", { name: "حذف الاختيار 2" }));
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(callsTo(fetchMock, "POST /api/dashboard/fields")).toHaveLength(1));
    expect(screen.queryByLabelText("اسم الاختيار 3")).not.toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/fields")[0]?.body).toMatchObject({ options: ["الأول", "الثالث"] });
  });

  it("refuses a duplicated option before calling the api", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/fields": json(201, { data: selectField() }) });
    const user = userEvent.setup();
    renderWithQuery(<FieldDialog field={null} onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("الاسم الظاهر"), "الصف");
    await user.type(screen.getByLabelText("المفتاح (بالإنجليزية)"), "grade");
    await user.type(screen.getByLabelText("اسم الاختيار 1"), "الأول{enter}");
    await user.type(screen.getByLabelText("اسم الاختيار 2"), " الأول ");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    expect(await screen.findByText("هذا الاختيار مكرر")).toBeInTheDocument();
    expect(screen.getByLabelText("اسم الاختيار 2")).toHaveAttribute("aria-invalid", "true");
    expect(callsTo(fetchMock, "POST /api/dashboard/fields")).toHaveLength(0);
  });

  it("keeps the last option row", () => {
    renderWithQuery(<FieldDialog field={null} onClose={vi.fn()} />);

    expect(screen.getByRole("button", { name: "حذف الاختيار 1" })).toBeDisabled();
  });

  it("D69 binds a field to a section", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/fields": json(201, { data: selectField({ kind: "toys" }) }) });
    const user = userEvent.setup();
    renderWithQuery(<FieldDialog field={null} onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("الاسم الظاهر"), "العمر");
    await user.type(screen.getByLabelText("المفتاح (بالإنجليزية)"), "age");
    await user.selectOptions(screen.getByLabelText("النوع"), "text");
    await user.selectOptions(screen.getByLabelText("القسم"), "toys");
    await user.click(screen.getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(callsTo(fetchMock, "POST /api/dashboard/fields")).toHaveLength(1));
    expect(callsTo(fetchMock, "POST /api/dashboard/fields")[0]?.body).toMatchObject({ key: "age", kind: "toys" });
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
