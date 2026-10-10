import { vi } from "vitest";

/** next/navigation for components rendered outside the App Router (mocked in setup.ts). */
export const router = {
  replace: vi.fn(),
  push: vi.fn(),
  refresh: vi.fn(),
  back: vi.fn(),
  forward: vi.fn(),
  prefetch: vi.fn(),
};

export const searchParams = { value: new URLSearchParams() };

/** usePathname() and useParams() answers; tests set them per case. */
export const pathname = { value: "/" };
export const params = { value: {} as Record<string, string> };
