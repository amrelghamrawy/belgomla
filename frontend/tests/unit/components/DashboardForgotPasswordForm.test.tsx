import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DashboardForgotPasswordForm } from "@/components/dashboard/DashboardForgotPasswordForm";

import { callsTo, envelopeError, json, stubApi } from "../http";
import { renderWithQuery, router } from "../render";

async function askForCode(email = "owner@alnoor.test") {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText("البريد الإلكتروني"), email);
  await user.click(screen.getByRole("button", { name: "أرسل الكود" }));

  return user;
}

beforeEach(() => {
  router.replace.mockReset();
  vi.spyOn(toast, "success").mockImplementation(() => "t");
});

describe("DashboardForgotPasswordForm", () => {
  it("D112 asks for the email and says the code goes on WhatsApp to the user's mobile", () => {
    renderWithQuery(<DashboardForgotPasswordForm />);

    expect(screen.getByRole("heading", { name: "استعادة كلمة المرور" })).toBeInTheDocument();
    expect(
      screen.getByText("اكتب بريدك الإلكتروني، وسنرسل كود التحقق على واتساب إلى رقم الموبايل المسجل في حسابك."),
    ).toBeInTheDocument();
    expect(screen.getByLabelText("البريد الإلكتروني")).toHaveAttribute("dir", "ltr");
    expect(screen.getByRole("link", { name: "العودة لتسجيل الدخول" })).toHaveAttribute("href", "/dashboard/login");
  });

  it("validates the email before calling the API", async () => {
    const fetchMock = stubApi({});
    renderWithQuery(<DashboardForgotPasswordForm />);

    await askForCode("not-an-email");

    expect(await screen.findByText("اكتب بريدًا إلكترونيًا صحيحًا")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/auth/forgot-password")).toHaveLength(0);
  });

  it("D112 sends the code, then sets the new password and goes back to the login", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/auth/forgot-password": json(204),
      "POST /api/dashboard/auth/reset-password": json(204),
    });
    renderWithQuery(<DashboardForgotPasswordForm />);

    const user = await askForCode();

    expect(
      await screen.findByText(
        "إذا كان owner@alnoor.test مسجلًا وله رقم موبايل، فسيصلك كود من 6 أرقام على واتساب خلال دقيقة.",
      ),
    ).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/auth/forgot-password")[0]?.body).toEqual({
      email: "owner@alnoor.test",
    });

    await user.type(screen.getByLabelText("كود التحقق"), "123456");
    await user.type(screen.getByLabelText("كلمة المرور الجديدة"), "new-secret");
    await user.click(screen.getByRole("button", { name: "حفظ كلمة المرور" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/dashboard/login"));
    expect(callsTo(fetchMock, "POST /api/dashboard/auth/reset-password")[0]?.body).toEqual({
      email: "owner@alnoor.test",
      code: "123456",
      password: "new-secret",
    });
    expect(toast.success).toHaveBeenCalledWith("تم تغيير كلمة المرور، سجل الدخول الآن");
  });

  it("checks the code and the password length before calling the API", async () => {
    const fetchMock = stubApi({ "POST /api/dashboard/auth/forgot-password": json(204) });
    renderWithQuery(<DashboardForgotPasswordForm />);

    const user = await askForCode();
    await user.type(await screen.findByLabelText("كود التحقق"), "12");
    await user.type(screen.getByLabelText("كلمة المرور الجديدة"), "short");
    await user.click(screen.getByRole("button", { name: "حفظ كلمة المرور" }));

    expect(await screen.findByText("الكود 6 أرقام")).toBeInTheDocument();
    expect(screen.getByText("كلمة المرور 8 أحرف على الأقل")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/dashboard/auth/reset-password")).toHaveLength(0);
  });

  it("shows a wrong code from the envelope and stays on the step", async () => {
    stubApi({
      "POST /api/dashboard/auth/forgot-password": json(204),
      "POST /api/dashboard/auth/reset-password": envelopeError(422, "otp_invalid", "رمز التحقق غير صحيح", {
        attempts_left: 4,
      }),
    });
    renderWithQuery(<DashboardForgotPasswordForm />);

    const user = await askForCode();
    await user.type(await screen.findByLabelText("كود التحقق"), "999999");
    await user.type(screen.getByLabelText("كلمة المرور الجديدة"), "new-secret");
    await user.click(screen.getByRole("button", { name: "حفظ كلمة المرور" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("رمز التحقق غير صحيح");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("sends the code again, and shows the hourly limit", async () => {
    const fetchMock = stubApi({
      "POST /api/dashboard/auth/forgot-password": [
        json(204),
        json(204),
        envelopeError(429, "rate_limited", "طلبت رموزًا كثيرة لهذا الحساب، حاول بعد قليل", { retry_after: 600 }),
      ],
    });
    renderWithQuery(<DashboardForgotPasswordForm />);

    const user = await askForCode();
    await user.click(await screen.findByRole("button", { name: "إعادة إرسال الكود" }));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم إرسال كود جديد"));

    await user.click(screen.getByRole("button", { name: "إعادة إرسال الكود" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("طلبت رموزًا كثيرة لهذا الحساب، حاول بعد قليل");
    expect(callsTo(fetchMock, "POST /api/dashboard/auth/forgot-password")).toHaveLength(3);
  });

  it("goes back to the email to change it", async () => {
    stubApi({ "POST /api/dashboard/auth/forgot-password": json(204) });
    renderWithQuery(<DashboardForgotPasswordForm />);

    const user = await askForCode();
    await user.click(await screen.findByRole("button", { name: "تغيير البريد" }));

    expect(screen.getByLabelText("البريد الإلكتروني")).toHaveValue("");
    expect(screen.getByRole("heading", { name: "استعادة كلمة المرور" })).toBeInTheDocument();
  });

  it("shows an error that is not about a field, such as a suspended store", async () => {
    stubApi({
      "POST /api/dashboard/auth/forgot-password": envelopeError(403, "tenant_suspended", "المتجر موقوف"),
    });
    renderWithQuery(<DashboardForgotPasswordForm />);

    await askForCode();

    expect(await screen.findByRole("alert")).toHaveTextContent("المتجر موقوف");
  });
});
