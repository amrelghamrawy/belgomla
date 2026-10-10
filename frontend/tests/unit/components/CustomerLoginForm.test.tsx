import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it } from "vitest";

import { customerLoginSchema, CustomerLoginForm } from "@/components/store/CustomerLoginForm";

import { csrfCookie, envelopeError, json, requestOf, stubFetch } from "../http";
import { renderWithQuery, router, searchParams } from "../render";

beforeEach(() => {
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
});

async function fillAndSubmit(phone: string, password: string) {
  const user = userEvent.setup();

  await user.type(screen.getByLabelText("رقم الهاتف"), phone);
  await user.type(screen.getByLabelText("كلمة المرور"), password);
  await user.click(screen.getByRole("button", { name: "دخول" }));
}

describe("customerLoginSchema", () => {
  it.each(["01012345678", "+201012345678", "201112345678", "1512345678"])("accepts the Egyptian mobile %s", (phone) => {
    expect(customerLoginSchema.safeParse({ phone, password: "x" }).success).toBe(true);
  });

  it.each(["0101234567", "01312345678", "0223456789", "abc"])("rejects %s", (phone) => {
    expect(customerLoginSchema.safeParse({ phone, password: "x" }).success).toBe(false);
  });
});

describe("CustomerLoginForm", () => {
  it("logs in with phone and password and returns home", async () => {
    const fetchMock = stubFetch(csrfCookie(), json(200, { data: { id: 7 } }));
    renderWithQuery(<CustomerLoginForm />);

    await fillAndSubmit("01012345678", "password");

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/"));
    expect(requestOf(fetchMock, 1).init.body).toBe('{"phone":"01012345678","password":"password"}');
  });

  it("shows why an account cannot log in yet", async () => {
    stubFetch(csrfCookie(), envelopeError(403, "pending_approval", "حسابك في انتظار موافقة المتجر"));
    renderWithQuery(<CustomerLoginForm />);

    await fillAndSubmit("01012345678", "password");

    expect(await screen.findByRole("alert")).toHaveTextContent("حسابك في انتظار موافقة المتجر");
  });

  it("flags an invalid phone before calling the API", async () => {
    const fetchMock = stubFetch();
    renderWithQuery(<CustomerLoginForm />);

    await fillAndSubmit("0123", "password");

    expect(await screen.findByText("اكتب رقم هاتف مصري صحيح")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
