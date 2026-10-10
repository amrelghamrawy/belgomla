import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { useCountdown } from "@/lib/store/countdown";

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("useCountdown", () => {
  it("counts the seconds down to zero and stops", () => {
    const { result } = renderHook(() => useCountdown(3));

    expect(result.current.remaining).toBe(3);
    act(() => vi.advanceTimersByTime(1000));
    expect(result.current.remaining).toBe(2);
    act(() => vi.advanceTimersByTime(5000));
    expect(result.current.remaining).toBe(0);
    expect(vi.getTimerCount()).toBe(0);
  });

  it("starts again from a new number of seconds", () => {
    const { result } = renderHook(() => useCountdown(1));
    act(() => vi.advanceTimersByTime(1000));

    act(() => result.current.restart(90));

    expect(result.current.remaining).toBe(90);
    act(() => vi.advanceTimersByTime(30_000));
    expect(result.current.remaining).toBe(60);
  });
});
