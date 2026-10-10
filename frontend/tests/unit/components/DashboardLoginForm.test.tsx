import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it } from "vitest";

import { DashboardLoginForm } from "@/components/dashboard/DashboardLoginForm";

import { csrfCookie, envelopeError, json, requestOf, stubFetch } from "../http";
import { renderWithQuery, router, searchParams } from "../render";

async function fillAndSubmit(email: string, password: string) {
  const user = userEvent.setup();

  if (email !== "") await user.type(screen.getByLabelText("البريد الإلكتروني"), email);
  if (password !== "") await user.type(screen.getByLabelText("كلمة المرور"), password);
  await user.click(screen.getByRole("button", { name: "دخول" }));
}

beforeEach(() => {
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

describe("DashboardLoginForm", () => {
  it("renders the Arabic form with left-to-right inputs for email and password", () => {
    renderWithQuery(<DashboardLoginForm />);

    expect(screen.getByRole("heading", { name: "دخول لوحة التحكم" })).toBeInTheDocument();
    expect(screen.getByLabelText("البريد الإلكتروني")).toHaveAttribute("dir", "ltr");
    expect(screen.getByLabelText("كلمة المرور")).toHaveAttribute("dir", "ltr");
  });

  it("validates on the client before calling the API", async () => {
    const fetchMock = stubFetch();
    renderWithQuery(<DashboardLoginForm />);

    await fillAndSubmit("not-an-email", "");

    expect(await screen.findByText("اكتب بريدًا إلكترونيًا صحيحًا")).toBeInTheDocument();
    expect(screen.getByText("هذا الحقل مطلوب")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("logs in and goes to the dashboard", async () => {
    const fetchMock = stubFetch(csrfCookie(), json(200, { data: { id: 1 } }));
    renderWithQuery(<DashboardLoginForm />);

    await fillAndSubmit("owner@alnoor.test", "password");

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/dashboard"));
    expect(requestOf(fetchMock, 1).url).toBe("/api/dashboard/auth/login");
  });

  it("returns to the page in ?next= after login", async () => {
    searchParams.value = new URLSearchParams("next=/dashboard/orders");
    stubFetch(csrfCookie(), json(200, { data: { id: 1 } }));
    renderWithQuery(<DashboardLoginForm />);

    await fillAndSubmit("owner@alnoor.test", "password");

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/dashboard/orders"));
  });

  it("shows the Arabic message from the envelope on wrong credentials", async () => {
    stubFetch(csrfCookie(), envelopeError(422, "invalid_credentials", "البريد الإلكتروني أو كلمة المرور غير صحيحة"));
    renderWithQuery(<DashboardLoginForm />);

    await fillAndSubmit("owner@alnoor.test", "wrong");

    expect(await screen.findByRole("alert")).toHaveTextContent("البريد الإلكتروني أو كلمة المرور غير صحيحة");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("puts server validation messages under their fields", async () => {
    stubFetch(
      csrfCookie(),
      envelopeError(422, "validation_failed", "راجع البيانات", {
        email: ["يجب أن يكون البريد الإلكتروني بريدًا إلكترونيًا صالحًا."],
      }),
    );
    renderWithQuery(<DashboardLoginForm />);

    await fillAndSubmit("owner@alnoor.test", "password");

    expect(await screen.findByText("يجب أن يكون البريد الإلكتروني بريدًا إلكترونيًا صالحًا.")).toBeInTheDocument();
    expect(screen.getByLabelText("البريد الإلكتروني")).toHaveAttribute("aria-invalid", "true");
  });
});
