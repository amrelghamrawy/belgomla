import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DashboardHome } from "@/components/dashboard/DashboardHome";

import { csrfCookie, envelopeError, json, requestOf, stubFetch } from "../http";
import { renderWithQuery, router } from "../render";

const me = {
  user: { id: 1, name: "أحمد", email: "owner@alnoor.test", role: "owner", status: "active", last_login_at: null },
  tenant: { name: "مكتبة النور", subdomain: "alnoor", status: "active", vertical: "school-books" },
};

beforeEach(() => {
  router.replace.mockReset();
});

describe("DashboardHome", () => {
  it("shows the logged-in user, their role and the store", async () => {
    stubFetch(json(200, { data: me }));

    renderWithQuery(<DashboardHome />);

    expect(await screen.findByTestId("user-name")).toHaveTextContent("أحمد");
    expect(screen.getByTestId("tenant-name")).toHaveTextContent("مكتبة النور");
    expect(screen.getByText("مالك")).toBeInTheDocument();
    expect(screen.getByText("owner@alnoor.test").tagName).toBe("BDI");
  });

  it("sends a guest to the dashboard login", async () => {
    stubFetch(envelopeError(401, "unauthenticated", "يجب تسجيل الدخول"));

    renderWithQuery(<DashboardHome />);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/dashboard/login?next=%2Fdashboard"));
  });

  it("shows the error when the session cannot be read", async () => {
    stubFetch(envelopeError(403, "tenant_suspended", "تم إيقاف المتجر"));

    renderWithQuery(<DashboardHome />);

    expect(await screen.findByText("تم إيقاف المتجر")).toBeInTheDocument();
  });

  it("logs out and reloads on the login page", async () => {
    const assign = vi.fn();
    vi.stubGlobal("location", { ...window.location, assign });
    const fetchMock = stubFetch(json(200, { data: me }), csrfCookie(), json(204));
    renderWithQuery(<DashboardHome />);

    await userEvent.setup().click(await screen.findByRole("button", { name: "تسجيل الخروج" }));

    await waitFor(() => expect(assign).toHaveBeenCalledWith("/dashboard/login"));
    expect(requestOf(fetchMock, 2).url).toBe("/api/dashboard/auth/logout");
  });
});
