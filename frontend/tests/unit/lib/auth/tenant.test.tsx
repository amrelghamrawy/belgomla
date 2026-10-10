import { waitFor } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { useTenant } from "@/lib/auth/tenant";

import { tenantSettings } from "../../fixtures";
import { json, requestOf, stubFetch } from "../../http";
import { renderHookWithQuery } from "../../render";

describe("useTenant", () => {
  it("loads the store settings and applies the branding", async () => {
    const fetchMock = stubFetch(
      json(200, { data: tenantSettings({ colors: { primary: "#1B3A8C", secondary: "#FFD54F" } }) }),
    );

    const { result } = renderHookWithQuery(() => useTenant());

    await waitFor(() => expect(result.current.data?.name).toBe("مكتبة النور"));
    expect(requestOf(fetchMock, 0).url).toBe("/api/store/tenant");
    await waitFor(() => expect(document.documentElement.style.getPropertyValue("--brand-primary")).toBe("#1B3A8C"));
    expect(document.title).toBe("مكتبة النور");
  });
});
