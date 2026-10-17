import { type RefObject, useEffect } from "react";

/** Time between two steps of a rail. */
export const AUTO_SCROLL_INTERVAL = 4000;
/** After the shopper touches, scrolls or clicks in a rail, it waits this long before moving again. */
export const AUTO_SCROLL_RESUME_AFTER = 8000;

/**
 * Moves a horizontal scroller one item towards its end, or back to its start once the end is showing.
 * Works in RTL, where scrollLeft runs from 0 to negative values.
 */
export function advanceRail(rail: HTMLElement): void {
  const first = rail.firstElementChild;
  if (!(first instanceof HTMLElement) || rail.scrollWidth <= rail.clientWidth) {
    return;
  }

  const gap = Number.parseFloat(getComputedStyle(rail).columnGap) || 0;
  const step = first.offsetWidth + gap;
  const sign = getComputedStyle(rail).direction === "rtl" ? -1 : 1;
  const atEnd = Math.abs(rail.scrollLeft) + rail.clientWidth >= rail.scrollWidth - 1;

  if (atEnd) {
    rail.scrollTo({ left: 0, behavior: "smooth" });
  } else {
    rail.scrollBy({ left: sign * step, behavior: "smooth" });
  }
}

/**
 * Turns a rail by itself (D67): one item every few seconds, looping back to the start. It holds still while the
 * pointer is over it or focus is inside it, for a while after the shopper moves it by hand, while the tab is hidden,
 * and never moves for shoppers who ask for reduced motion.
 */
export function useAutoScroll(ref: RefObject<HTMLElement | null>, enabled: boolean): void {
  useEffect(() => {
    const rail = ref.current;
    if (!enabled || rail === null || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      return;
    }

    let hovered = false;
    let focused = false;
    let pausedUntil = 0;

    const hold = () => {
      pausedUntil = Date.now() + AUTO_SCROLL_RESUME_AFTER;
    };
    const onEnter = () => {
      hovered = true;
    };
    const onLeave = () => {
      hovered = false;
    };
    const onFocusIn = () => {
      focused = true;
    };
    const onFocusOut = (event: FocusEvent) => {
      focused = event.relatedTarget instanceof Node && rail.contains(event.relatedTarget);
    };

    rail.addEventListener("pointerenter", onEnter);
    rail.addEventListener("pointerleave", onLeave);
    rail.addEventListener("focusin", onFocusIn);
    rail.addEventListener("focusout", onFocusOut);
    rail.addEventListener("pointerdown", hold);
    rail.addEventListener("touchstart", hold, { passive: true });
    rail.addEventListener("wheel", hold, { passive: true });

    const timer = window.setInterval(() => {
      if (hovered || focused || document.hidden || Date.now() < pausedUntil) {
        return;
      }
      advanceRail(rail);
    }, AUTO_SCROLL_INTERVAL);

    return () => {
      window.clearInterval(timer);
      rail.removeEventListener("pointerenter", onEnter);
      rail.removeEventListener("pointerleave", onLeave);
      rail.removeEventListener("focusin", onFocusIn);
      rail.removeEventListener("focusout", onFocusOut);
      rail.removeEventListener("pointerdown", hold);
      rail.removeEventListener("touchstart", hold);
      rail.removeEventListener("wheel", hold);
    };
  }, [ref, enabled]);
}
