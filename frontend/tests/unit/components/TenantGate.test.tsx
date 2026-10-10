import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { TenantGate } from "@/components/shared/TenantGate";

import { tenantSettings } from "../fixtures";
import { envelopeError, json, stubFetch } from "../http";
import { renderWithQuery } from "../render";

describe("TenantGate", () => {
  it("renders the page for an active store", async () => {
    stubFetch(json(200, { data: tenantSettings() }));

    renderWithQuery(<TenantGate>محتوى الصفحة</TenantGate>);

    expect(await screen.findByText("محتوى الصفحة")).toBeInTheDocument();
  });

  it("BR-T07 replaces the page when the store is suspended", async () => {
    stubFetch(envelopeError(503, "store_unavailable", "المتجر غير متاح"));

    renderWithQuery(<TenantGate>محتوى الصفحة</TenantGate>);

    expect(await screen.findByRole("heading", { name: "المتجر غير متاح" })).toBeInTheDocument();
    expect(screen.queryByText("محتوى الصفحة")).not.toBeInTheDocument();
  });

  it("shows the backend message when the host is not a store", async () => {
    stubFetch(envelopeError(404, "not_found", "المتجر غير موجود"));

    renderWithQuery(<TenantGate>محتوى الصفحة</TenantGate>);

    expect(await screen.findByRole("heading", { name: "المتجر غير موجود" })).toBeInTheDocument();
  });
});
