import { describe, expect, it } from "vitest";

import { makeQueryClient, shouldRetry } from "@/app/providers";
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
