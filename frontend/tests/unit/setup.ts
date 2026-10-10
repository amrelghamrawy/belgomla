import "@testing-library/jest-dom/vitest";

import { cleanup } from "@testing-library/react";
import { afterEach, vi } from "vitest";

import { router, searchParams } from "./navigation";

vi.mock("next/navigation", () => ({
  useRouter: () => router,
  useSearchParams: () => searchParams.value,
  usePathname: () => "/",
}));

afterEach(() => {
  // Tests that run in the node environment (the backend proxy) have no DOM.
  if (typeof document === "undefined") {
    return;
  }

  cleanup();
  // Cookies are global in jsdom; every test starts without the CSRF cookie.
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
});
