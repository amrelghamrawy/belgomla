import { describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";
import { exportParams, isFinished, waitForExport } from "@/lib/dashboard/catalog-files";

import { envelopeError, stubFetch } from "../../http";

const noWait = () => Promise.resolve();

function file(name: string) {
  return new Response("bytes", { status: 200, headers: { "Content-Disposition": `attachment; filename=${name}` } });
}

describe("exportParams", () => {
  it("V2 Task 06 exports the list's search, category, status and facets, never its page, sort or low stock", () => {
    expect(
      exportParams(
        { q: "علوم", category: "4", status: "active", "f[grade]": "7", page: "3", sort: "-name", low_stock: "1" },
        "csv",
      ),
    ).toEqual({ format: "csv", q: "علوم", category: "4", status: "active", "f[grade]": "7" });
  });
});

describe("waitForExport", () => {
  it("polls the signed link until the file is written", async () => {
    const fetchMock = stubFetch(
      envelopeError(409, "export_not_ready", "الملف قيد التجهيز"),
      envelopeError(409, "export_not_ready", "الملف قيد التجهيز"),
      file("products.xlsx"),
    );

    const result = await waitForExport("/api/dashboard/products/export/e1?signature=x", noWait);

    expect(result.fileName).toBe("products.xlsx");
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it("stops at once on another error", async () => {
    stubFetch(envelopeError(404, "not_found", "رابط التحميل غير صالح"));

    await expect(waitForExport("/x", noWait)).rejects.toMatchObject({ code: "not_found" });
  });

  it("gives up as not ready after its attempts", async () => {
    const sleep = vi.fn(noWait);
    stubFetch(envelopeError(409, "export_not_ready", "…"), envelopeError(409, "export_not_ready", "…"));

    await expect(waitForExport("/x", sleep, 2)).rejects.toBeInstanceOf(ApiError);
    expect(sleep).toHaveBeenCalledTimes(2);
  });
});

describe("isFinished", () => {
  it("is true once the import is done or failed", () => {
    expect(isFinished(undefined)).toBe(false);
    expect(isFinished({ status: "running" } as never)).toBe(false);
    expect(isFinished({ status: "done" } as never)).toBe(true);
    expect(isFinished({ status: "failed" } as never)).toBe(true);
  });
});
