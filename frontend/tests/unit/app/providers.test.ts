import { act, renderHook } from "@testing-library/react";
import { toast } from "sonner";
import { describe, expect, it, vi } from "vitest";

import { makeQueryClient, shouldRetry, useConnectionToasts } from "@/app/providers";
import { ApiError, StoreUnavailableError } from "@/lib/api/errors";
import { queryKeys } from "@/lib/api/keys";

describe("shouldRetry", () => {
  it.each([
    ["a 4xx error", new ApiError(422, "validation_failed", "x"), 0, false],
    ["a 401", new ApiError(401, "unauthenticated", "x"), 0, false],
    ["a network error, first failure", new ApiError(0, "network_error", "x"), 0, true],
    ["a 5xx, second failure", new ApiError(500, "server_error", "x"), 1, true],
    ["a 5xx, third failure", new ApiError(502, "server_error", "x"), 2, false],
  ])("decides for %s", (_, error, failures, expected) => {
    expect(shouldRetry(failures, error)).toBe(expected);
  });
});

describe("makeQueryClient", () => {
  it("re-checks the tenant when any query finds the store unavailable", async () => {
    const client = makeQueryClient();
    client.setQueryData(queryKeys.tenant, { name: "x" });

    await client
      .fetchQuery({
        queryKey: ["store", "products"],
        queryFn: () => Promise.reject(new StoreUnavailableError(503, "store_unavailable", "x")),
        retry: false,
      })
      .catch(() => undefined);

    expect(client.getQueryState(queryKeys.tenant)?.isInvalidated).toBe(true);
  });
});

describe("network toasts", () => {
  it("offers a retry when a read fails for lack of connection, and the retry refetches failed queries", async () => {
    const error = vi.spyOn(toast, "error").mockImplementation(() => "network");
    const client = makeQueryClient();
    const queryFn = vi.fn().mockRejectedValue(new ApiError(0, "network_error", "x"));

    await client.fetchQuery({ queryKey: ["store", "cart"], queryFn, retry: false }).catch(() => undefined);

    expect(error).toHaveBeenCalledWith(
      "تعذر الاتصال، تأكد من الإنترنت وحاول مرة أخرى",
      expect.objectContaining({ id: "network" }),
    );
    const action = error.mock.calls[0]?.[1]?.action as { label: string; onClick: () => void };
    expect(action.label).toBe("حاول مرة أخرى");
    queryFn.mockResolvedValue({ items: [] });
    action.onClick();
    await vi.waitFor(() => expect(client.getQueryData(["store", "cart"])).toEqual({ items: [] }));
  });

  it("stays quiet for errors that are not about the connection", async () => {
    const error = vi.spyOn(toast, "error").mockImplementation(() => "x");
    const client = makeQueryClient();

    await client
      .fetchQuery({ queryKey: ["x"], queryFn: () => Promise.reject(new ApiError(404, "not_found", "x")), retry: false })
      .catch(() => undefined);

    expect(error).not.toHaveBeenCalled();
  });

  it("tells the shopper when the connection drops and comes back", () => {
    const warning = vi.spyOn(toast, "warning").mockImplementation(() => "network");
    const success = vi.spyOn(toast, "success").mockImplementation(() => "network");
    renderHook(() => useConnectionToasts());

    act(() => {
      window.dispatchEvent(new Event("offline"));
      window.dispatchEvent(new Event("online"));
    });

    expect(warning).toHaveBeenCalledWith("انقطع الاتصال بالإنترنت", { id: "network" });
    expect(success).toHaveBeenCalledWith("عاد الاتصال بالإنترنت", { id: "network" });
  });
});
