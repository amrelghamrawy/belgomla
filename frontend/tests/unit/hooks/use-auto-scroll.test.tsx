import { act, fireEvent, render } from "@testing-library/react";
import { useRef } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AUTO_SCROLL_INTERVAL, AUTO_SCROLL_RESUME_AFTER, advanceRail, useAutoScroll } from "@/hooks/use-auto-scroll";

/** jsdom has no layout: give the rail the sizes of four 100px cards with a 12px gap in a 224px window. */
function measure(rail: HTMLElement, scrollLeft = 0) {
  Object.defineProperty(rail, "scrollWidth", { configurable: true, value: 436 });
  Object.defineProperty(rail, "clientWidth", { configurable: true, value: 224 });
  Object.defineProperty(rail, "scrollLeft", { configurable: true, value: scrollLeft });
  for (const item of rail.children) {
    Object.defineProperty(item, "offsetWidth", { configurable: true, value: 100 });
  }
  const scrollBy = vi.fn();
  const scrollTo = vi.fn();
  rail.scrollBy = scrollBy;
  rail.scrollTo = scrollTo;

  return { scrollBy, scrollTo };
}

function Rail({ enabled = true }: { enabled?: boolean }) {
  const ref = useRef<HTMLUListElement>(null);
  useAutoScroll(ref, enabled);

  return (
    <ul ref={ref} data-testid="rail" style={{ direction: "rtl", columnGap: "12px" }}>
      {[1, 2, 3, 4].map((n) => (
        <li key={n}>
          <a href={`#${n}`}>{n}</a>
        </li>
      ))}
    </ul>
  );
}

function renderRail(enabled = true) {
  const view = render(<Rail enabled={enabled} />);
  const rail = view.getByTestId("rail");

  return { view, rail, ...measure(rail) };
}

function reducedMotion(matches: boolean) {
  vi.spyOn(window, "matchMedia").mockImplementation(
    (query: string) => ({ matches, media: query }) as unknown as MediaQueryList,
  );
}

describe("advanceRail", () => {
  it("moves one card plus the gap towards the end of an rtl rail", () => {
    const rail = render(
      <ul data-testid="rail" style={{ direction: "rtl", columnGap: "12px" }}>
        <li />
      </ul>,
    ).getByTestId("rail");
    const { scrollBy } = measure(rail);

    advanceRail(rail);

    expect(scrollBy).toHaveBeenCalledWith({ left: -112, behavior: "smooth" });
  });

  it("moves right on an ltr rail", () => {
    const rail = render(
      <ul data-testid="rail" style={{ direction: "ltr", columnGap: "12px" }}>
        <li />
      </ul>,
    ).getByTestId("rail");
    const { scrollBy } = measure(rail);

    advanceRail(rail);

    expect(scrollBy).toHaveBeenCalledWith({ left: 112, behavior: "smooth" });
  });

  it("goes back to the start once the last card is showing", () => {
    const rail = render(
      <ul data-testid="rail" style={{ direction: "rtl" }}>
        <li />
      </ul>,
    ).getByTestId("rail");
    const { scrollBy, scrollTo } = measure(rail, -212);

    advanceRail(rail);

    expect(scrollTo).toHaveBeenCalledWith({ left: 0, behavior: "smooth" });
    expect(scrollBy).not.toHaveBeenCalled();
  });

  it("does nothing when every card already fits", () => {
    const rail = render(
      <ul data-testid="rail">
        <li />
      </ul>,
    ).getByTestId("rail");
    const { scrollBy, scrollTo } = measure(rail);
    Object.defineProperty(rail, "clientWidth", { configurable: true, value: 436 });

    advanceRail(rail);

    expect(scrollBy).not.toHaveBeenCalled();
    expect(scrollTo).not.toHaveBeenCalled();
  });
});

describe("useAutoScroll", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    reducedMotion(false);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it("moves the rail by itself every few seconds", () => {
    const { scrollBy } = renderRail();

    act(() => vi.advanceTimersByTime(AUTO_SCROLL_INTERVAL * 2));

    expect(scrollBy).toHaveBeenCalledTimes(2);
  });

  it("stays still when it is not enabled", () => {
    const { scrollBy } = renderRail(false);

    act(() => vi.advanceTimersByTime(AUTO_SCROLL_INTERVAL * 2));

    expect(scrollBy).not.toHaveBeenCalled();
  });

  it("stays still for shoppers who ask for reduced motion", () => {
    reducedMotion(true);
    const { scrollBy } = renderRail();

    act(() => vi.advanceTimersByTime(AUTO_SCROLL_INTERVAL * 2));

    expect(scrollBy).not.toHaveBeenCalled();
  });

  it("holds while the pointer is over the rail and resumes when it leaves", () => {
    const { rail, scrollBy } = renderRail();

    fireEvent.pointerEnter(rail);
    act(() => vi.advanceTimersByTime(AUTO_SCROLL_INTERVAL * 2));
    expect(scrollBy).not.toHaveBeenCalled();

    fireEvent.pointerLeave(rail);
    act(() => vi.advanceTimersByTime(AUTO_SCROLL_INTERVAL));
    expect(scrollBy).toHaveBeenCalledTimes(1);
  });

  it("holds while focus is inside the rail", () => {
    const { view, scrollBy } = renderRail();

    act(() => view.getByRole("link", { name: "2" }).focus());
    act(() => vi.advanceTimersByTime(AUTO_SCROLL_INTERVAL * 2));

    expect(scrollBy).not.toHaveBeenCalled();
  });

  it("waits a while after the shopper swipes the rail by hand", () => {
    const { rail, scrollBy } = renderRail();

    fireEvent.touchStart(rail);
    act(() => vi.advanceTimersByTime(AUTO_SCROLL_RESUME_AFTER - 1));
    expect(scrollBy).not.toHaveBeenCalled();

    act(() => vi.advanceTimersByTime(AUTO_SCROLL_INTERVAL));
    expect(scrollBy).toHaveBeenCalledTimes(1);
  });

  it("stops when the rail unmounts", () => {
    const { view, scrollBy } = renderRail();

    view.unmount();
    act(() => vi.advanceTimersByTime(AUTO_SCROLL_INTERVAL * 2));

    expect(scrollBy).not.toHaveBeenCalled();
  });
});
