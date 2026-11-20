import { afterEach, describe, expect, it, vi } from "vitest";

import { saveDownload } from "@/lib/api/download";

afterEach(() => {
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe("saveDownload", () => {
  it("V2 Task 06 hands the file to the browser under its name and frees the object url", () => {
    vi.useFakeTimers();
    const blob = new Blob(["x"]);
    const create = vi.fn(() => "blob:file");
    const revoke = vi.fn();
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke });
    const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(function (this: HTMLAnchorElement) {
      expect(this.download).toBe("products.xlsx");
      expect(this.href).toBe("blob:file");
    });

    saveDownload({ blob, fileName: "products.xlsx" });
    vi.runAllTimers();

    expect(create).toHaveBeenCalledWith(blob);
    expect(click).toHaveBeenCalledOnce();
    expect(revoke).toHaveBeenCalledWith("blob:file");
    expect(document.querySelector("a[download]")).toBeNull();
  });
});
