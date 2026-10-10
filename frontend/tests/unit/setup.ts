import "@testing-library/jest-dom/vitest";

import { cleanup } from "@testing-library/react";
import { afterEach, vi } from "vitest";

import { params, pathname, router, searchParams } from "./navigation";

// Radix measures elements (radio items, dialogs); jsdom has no ResizeObserver. Assigned, not vi.stubGlobal:
// unstubGlobals would remove it after the first test.
if (typeof window !== "undefined") {
  window.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
}

// input-otp probes for password-manager badges with elementFromPoint, which jsdom does not implement.
if (typeof document !== "undefined" && typeof document.elementFromPoint !== "function") {
  document.elementFromPoint = () => null;
}

vi.mock("next/navigation", () => ({
  useRouter: () => router,
  useSearchParams: () => searchParams.value,
  usePathname: () => pathname.value,
  useParams: () => params.value,
}));

afterEach(() => {
  // Tests that run in the node environment (the backend proxy) have no DOM.
  if (typeof document === "undefined") {
    return;
  }

  cleanup();
  pathname.value = "/";
  params.value = {};
  // Cookies are global in jsdom; every test starts without the CSRF cookie.
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
});
