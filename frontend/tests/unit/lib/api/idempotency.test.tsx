import { renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { newIdempotencyKey, useIdempotencyKey } from "@/lib/api/idempotency";

describe("useIdempotencyKey", () => {
  it("D55 reuses the key while the same body is retried", () => {
    const { result } = renderHook(() => useIdempotencyKey("transition"));

    const first = result.current.keyFor({ to: "confirmed" });

    expect(result.current.keyFor({ to: "confirmed" })).toBe(first);
    expect(first).toMatch(/^transition-[0-9a-f]{32}$/);
  });

  it("makes a new key for a different body and after a reset", () => {
    const { result } = renderHook(() => useIdempotencyKey("collection"));
    const first = result.current.keyFor({ amount: "100.00" });

    const changed = result.current.keyFor({ amount: "150.00" });
    result.current.reset();

    expect(changed).not.toBe(first);
    expect(result.current.keyFor({ amount: "150.00" })).not.toBe(changed);
  });

  it("matches the backend's key pattern", () => {
    expect(newIdempotencyKey("void")).toMatch(/^[A-Za-z0-9_\-:.]{1,255}$/);
  });
});
