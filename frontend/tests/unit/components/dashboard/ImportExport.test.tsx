import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ImportWizard } from "@/components/dashboard/catalog/ImportWizard";
import { ProductsPage } from "@/components/dashboard/catalog/ProductsPage";
import { saveDownload } from "@/lib/api/download";
import type { CatalogImport, ImportPreview } from "@/lib/api/types";

import { dashboardMe, dashboardProduct, signedInAs } from "../../dashboard-fixtures";
import { page } from "../../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { renderWithQuery, searchParams } from "../../render";

vi.mock("@/lib/api/download", () => ({ saveDownload: vi.fn() }));

beforeEach(() => {
  vi.mocked(saveDownload).mockReset();
  searchParams.value = new URLSearchParams();
});

function preview(overrides: Partial<ImportPreview> = {}): ImportPreview {
  return {
    token: "6c1d1b8e-1a4b-4a8d-9a52-1f1f2a3b4c5d",
    file_name: "products.xlsx",
    total: 3,
    create: 1,
    update: 1,
    skip: 1,
    errors: [
      {
        row: 5,
        column: "base_unit",
        label: "الوحدة الأساسية",
        message: "الوحدة غير موجودة، أضفها أولًا من صفحة الوحدات",
      },
    ],
    errors_truncated: false,
    ...overrides,
  };
}

function run(overrides: Partial<CatalogImport> = {}): CatalogImport {
  return {
    id: 9,
    file_name: "products.xlsx",
    status: "queued",
    total: 0,
    processed: 0,
    created: 0,
    updated: 0,
    skipped: 0,
    failed: 0,
    errors: [],
    batch_id: "0d1c2b3a-4f5e-4d6c-8b7a-9f8e7d6c5b4a",
    started_at: null,
    finished_at: null,
    created_at: "2026-10-20T08:00:00Z",
    ...overrides,
  };
}

const xlsx = () =>
  new File(["x"], "products.xlsx", { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });

async function openAndPreview(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole("button", { name: "استيراد" }));
  await user.upload(screen.getByLabelText("الملف"), xlsx());
  await user.click(screen.getByRole("button", { name: "فحص الملف" }));
}

describe("ImportWizard", () => {
  it("V2 Task 06 previews a file: the counts and every row error with its row and column", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/products/import/preview": json(200, { data: preview() }) });
    const user = userEvent.setup();
    renderWithQuery(<ImportWizard />);

    await openAndPreview(user);

    const step = await screen.findByTestId("import-preview");
    expect(within(step).getByText("منتجات جديدة").nextSibling).toHaveTextContent("1");
    expect(within(step).getByText("صفوف بها أخطاء").nextSibling).toHaveTextContent("1");
    expect(
      within(screen.getByTestId("import-errors")).getByRole("row", { name: /5 الوحدة الأساسية/ }),
    ).toHaveTextContent("الوحدة غير موجودة، أضفها أولًا من صفحة الوحدات");
    expect(screen.getByRole("button", { name: "استيراد 2 منتج" })).toBeEnabled();
    expect(callsTo(fetchMock, "POST /api/dashboard/products/import/preview")).toHaveLength(1);
  });

  it("shows the file's own problem when the whole file is refused", async () => {
    stubApi({
      "POST /api/dashboard/products/import/preview": envelopeError(422, "validation_failed", "بعض البيانات غير صحيحة", {
        file: ["أعمدة ناقصة في الملف: سعر الجملة"],
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<ImportWizard />);

    await openAndPreview(user);

    expect(await screen.findByRole("alert")).toHaveTextContent("أعمدة ناقصة في الملف: سعر الجملة");
  });

  it("cannot import a file where every row has errors", async () => {
    stubApi({
      "POST /api/dashboard/products/import/preview": json(200, { data: preview({ total: 1, create: 0, update: 0 }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<ImportWizard />);

    await openAndPreview(user);

    expect(await screen.findByRole("button", { name: "لا يوجد صف صالح للاستيراد" })).toBeDisabled();
  });

  it("goes back to choose another file", async () => {
    stubApi({ "POST /api/dashboard/products/import/preview": json(200, { data: preview() }) });
    const user = userEvent.setup();
    renderWithQuery(<ImportWizard />);
    await openAndPreview(user);

    await user.click(await screen.findByRole("button", { name: "اختيار ملف آخر" }));

    expect(screen.getByRole("button", { name: "فحص الملف" })).toBeInTheDocument();
  });

  it("D55 confirms with an idempotency key, follows the progress and shows the summary", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/products/import/preview": json(200, { data: preview({ errors: [], skip: 0 }) }),
      "POST /api/dashboard/products/import": json(202, { data: run() }),
      "GET /api/dashboard/imports/9": [
        json(200, { data: run({ status: "running", total: 2, processed: 1 }) }),
        json(200, { data: run({ status: "done", total: 2, processed: 2, created: 1, updated: 1 }) }),
      ],
    });
    const user = userEvent.setup();
    renderWithQuery(<ImportWizard />);
    await openAndPreview(user);
    expect(await screen.findByText("لا توجد أخطاء، الملف جاهز للاستيراد")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "استيراد 2 منتج" }));

    expect(await screen.findByText("تمت معالجة 1 من 2 صف")).toBeInTheDocument();
    expect(await screen.findByRole("status", {}, { timeout: 4000 })).toHaveTextContent("اكتمل الاستيراد");
    expect(within(screen.getByTestId("import-summary")).getByText("تم تحديثها").nextSibling).toHaveTextContent("1");
    const [start] = callsTo(fetchMock, "POST /api/dashboard/products/import");
    expect(start?.body).toEqual({ token: "6c1d1b8e-1a4b-4a8d-9a52-1f1f2a3b4c5d" });
    expect(start?.headers["Idempotency-Key"]).toMatch(/^import-[0-9a-f]{32}$/);
  });

  it("shows why an import stopped", async () => {
    stubApi({
      "POST /api/dashboard/products/import/preview": json(200, { data: preview() }),
      "POST /api/dashboard/products/import": json(202, { data: run() }),
      "GET /api/dashboard/imports/9": json(200, {
        data: run({
          status: "failed",
          errors: [{ row: 0, column: "file", label: "الملف", message: "الملف المرفوع لم يعد موجودًا، ارفعه مرة أخرى" }],
        }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<ImportWizard />);
    await openAndPreview(user);

    await user.click(await screen.findByRole("button", { name: "استيراد 2 منتج" }));

    expect(await screen.findByRole("status")).toHaveTextContent("توقف الاستيراد");
    expect(screen.getByTestId("import-errors")).toHaveTextContent("الملف المرفوع لم يعد موجودًا، ارفعه مرة أخرى");
  });

  it("shows an expired token when the import is started too late", async () => {
    stubApi({
      "POST /api/dashboard/products/import/preview": json(200, { data: preview() }),
      "POST /api/dashboard/products/import": envelopeError(
        422,
        "import_expired",
        "انتهت صلاحية الملف المرفوع، ارفعه مرة أخرى",
      ),
    });
    const user = userEvent.setup();
    renderWithQuery(<ImportWizard />);
    await openAndPreview(user);

    await user.click(await screen.findByRole("button", { name: "استيراد 2 منتج" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("انتهت صلاحية الملف المرفوع، ارفعه مرة أخرى");
  });

  it("downloads the empty template", async () => {
    const fetchMock = stubApi({
      "GET /api/dashboard/products/import/template": () =>
        new Response("x", {
          headers: { "Content-Disposition": "attachment; filename=products-template-2026-10-20.xlsx" },
        }),
    });
    const user = userEvent.setup();
    renderWithQuery(<ImportWizard />);

    await user.click(screen.getByRole("button", { name: "استيراد" }));
    await user.click(screen.getByRole("button", { name: "تحميل قالب فارغ" }));

    await waitFor(() =>
      expect(saveDownload).toHaveBeenCalledWith(
        expect.objectContaining({ fileName: "products-template-2026-10-20.xlsx" }),
      ),
    );
    expect(callsTo(fetchMock, "GET /api/dashboard/products/import/template")[0]?.url.search).toBe("?format=xlsx");
  });
});

describe("ProductsPage catalog file", () => {
  const listRoutes = {
    "GET /api/dashboard/products": json(200, page([dashboardProduct()])),
    "GET /api/dashboard/categories": json(200, { data: [] }),
    "GET /api/dashboard/fields": json(200, { data: [] }),
  };

  it("D82 offers export and import only with catalog.manage", async () => {
    stubApi({ ...listRoutes, "GET /api/dashboard/me": json(200, { data: dashboardMe({ role: "sales" }) }) });
    renderWithQuery(<ProductsPage />, signedInAs("sales"));

    await screen.findByRole("link", { name: "الأضواء علوم" });
    expect(screen.queryByRole("button", { name: "تصدير" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "استيراد" })).not.toBeInTheDocument();
  });

  it("V2 Task 06 exports the list's current filter and saves the file", async () => {
    searchParams.value = new URLSearchParams("q=علوم&page=2&f[grade]=7");
    const fetchMock = stubApi({
      ...listRoutes,
      "GET /api/dashboard/products/export": () =>
        new Response("x", { headers: { "Content-Disposition": "attachment; filename=products-2026-10-20.xlsx" } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<ProductsPage />, signedInAs("owner"));

    await user.click(await screen.findByRole("button", { name: "تصدير" }));

    await waitFor(() =>
      expect(saveDownload).toHaveBeenCalledWith(expect.objectContaining({ fileName: "products-2026-10-20.xlsx" })),
    );
    const params = callsTo(fetchMock, "GET /api/dashboard/products/export")[0]?.url.searchParams;
    expect(Object.fromEntries(params ?? [])).toEqual({ format: "xlsx", q: "علوم", "f[grade]": "7" });
  });

  it("D95 a large export waits for its signed link, then saves the file", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const fetchMock = stubApi({
      ...listRoutes,
      "GET /api/dashboard/products/export": json(202, {
        data: { id: "e1", download_url: "/api/dashboard/products/export/e1?signature=s" },
      }),
      "GET /api/dashboard/products/export/e1": [
        envelopeError(409, "export_not_ready", "الملف قيد التجهيز، حاول بعد قليل"),
        new Response("x", { headers: { "Content-Disposition": "attachment; filename=products-2026-10-20.csv" } }),
      ],
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderWithQuery(<ProductsPage />, signedInAs("owner"));

    await user.click(await screen.findByRole("button", { name: "ملف CSV" }));
    await vi.advanceTimersByTimeAsync(5000);

    await waitFor(() =>
      expect(saveDownload).toHaveBeenCalledWith(expect.objectContaining({ fileName: "products-2026-10-20.csv" })),
    );
    expect(callsTo(fetchMock, "GET /api/dashboard/products/export/e1")).toHaveLength(2);
    vi.useRealTimers();
  });
});
