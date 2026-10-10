import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CustomerLoginForm } from "@/components/store/CustomerLoginForm";
import { ForgotPasswordForm } from "@/components/store/ForgotPasswordForm";
import { InvitationForm } from "@/components/store/InvitationForm";
import { RegisterForm, registerSchema } from "@/components/store/RegisterForm";
import { ResetPasswordForm } from "@/components/store/ResetPasswordForm";
import { forgetReset, pendingReset, rememberReset } from "@/lib/store/reset-flow";

import { customer } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { params, renderWithQuery, router, searchParams } from "../render";

beforeEach(() => {
  router.push.mockReset();
  router.replace.mockReset();
  searchParams.value = new URLSearchParams();
  forgetReset();
});

describe("registerSchema", () => {
  it("D50 needs a password of at least 8 characters", () => {
    expect(registerSchema.safeParse({ name: "م", phone: "01012345678", password: "1234567" }).success).toBe(false);
    expect(registerSchema.safeParse({ name: "م", phone: "01012345678", password: "12345678" }).success).toBe(true);
  });

  it("limits the name to 150 characters", () => {
    expect(
      registerSchema.safeParse({ name: "م".repeat(151), phone: "01012345678", password: "12345678" }).success,
    ).toBe(false);
  });
});

describe("RegisterForm", () => {
  async function register(name = "مكتبة الأمل", phone = "01012345678", password = "secret123") {
    const user = userEvent.setup();
    await user.type(screen.getByLabelText("الاسم أو اسم المكتبة"), name);
    await user.type(screen.getByLabelText("رقم الهاتف"), phone);
    await user.type(screen.getByLabelText("كلمة المرور"), password);
    await user.click(screen.getByRole("button", { name: "إنشاء حساب" }));
  }

  it("BR-CU04 creates the account and goes to the code step", async () => {
    searchParams.value = new URLSearchParams("next=/cart");
    const fetchMock = stubApi({ "POST /api/store/auth/register": json(200, { data: { otp_sent_via: "whatsapp" } }) });
    renderWithQuery(<RegisterForm />);

    await register();

    await waitFor(() =>
      expect(router.push).toHaveBeenCalledWith(
        "/account/verify?phone=01012345678&purpose=register&via=whatsapp&next=%2Fcart",
      ),
    );
    expect(callsTo(fetchMock, "POST /api/store/auth/register")[0]?.body).toEqual({
      name: "مكتبة الأمل",
      phone: "01012345678",
      password: "secret123",
    });
  });

  it("shows phone_taken on the phone field", async () => {
    stubApi({ "POST /api/store/auth/register": envelopeError(409, "phone_taken", "رقم الهاتف مسجل من قبل") });
    renderWithQuery(<RegisterForm />);

    await register();

    expect(await screen.findByText("رقم الهاتف مسجل من قبل")).toBeInTheDocument();
    expect(screen.getByLabelText("رقم الهاتف")).toHaveAttribute("aria-invalid", "true");
  });

  it("BR-CU08 tells an invited phone to use its invitation link", async () => {
    stubApi({
      "POST /api/store/auth/register": envelopeError(409, "use_invitation", "لديك دعوة من المتجر، استخدم رابط الدعوة"),
    });
    renderWithQuery(<RegisterForm />);

    await register();

    expect(await screen.findByRole("alert")).toHaveTextContent("لديك دعوة من المتجر، استخدم رابط الدعوة");
  });

  it("maps the backend's field errors", async () => {
    stubApi({
      "POST /api/store/auth/register": envelopeError(422, "validation_failed", "راجع البيانات", {
        name: ["الاسم مطلوب"],
      }),
    });
    renderWithQuery(<RegisterForm />);

    await register();

    expect(await screen.findByText("الاسم مطلوب")).toBeInTheDocument();
  });

  it("validates before calling the API", async () => {
    const fetchMock = stubApi({});
    renderWithQuery(<RegisterForm />);

    await register("مكتبة", "0123", "short");

    expect(await screen.findByText("اكتب رقم هاتف مصري صحيح")).toBeInTheDocument();
    expect(screen.getByText("كلمة المرور 8 أحرف على الأقل")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("CustomerLoginForm", () => {
  it("BR-CU07 a registered but unverified phone gets a new code and goes to the code step", async () => {
    const fetchMock = stubApi({
      "POST /api/store/auth/login": envelopeError(403, "phone_not_verified", "أكد رقم هاتفك أولًا"),
      "POST /api/store/auth/resend-otp": json(200, { data: { otp_sent_via: "whatsapp" } }),
    });
    const user = userEvent.setup();
    renderWithQuery(<CustomerLoginForm />);

    await user.type(screen.getByLabelText("رقم الهاتف"), "01012345678");
    await user.type(screen.getByLabelText("كلمة المرور"), "secret123");
    await user.click(screen.getByRole("button", { name: "دخول" }));

    await waitFor(() =>
      expect(router.push).toHaveBeenCalledWith("/account/verify?phone=01012345678&purpose=register&via=whatsapp"),
    );
    expect(callsTo(fetchMock, "POST /api/store/auth/resend-otp")[0]?.body).toEqual({
      phone: "01012345678",
      purpose: "register",
    });
  });

  it("links to registration (keeping ?next=) and to the forgotten password", () => {
    searchParams.value = new URLSearchParams("next=/cart");
    stubApi({});
    renderWithQuery(<CustomerLoginForm />);

    expect(screen.getByRole("link", { name: "إنشاء حساب" })).toHaveAttribute("href", "/account/register?next=%2Fcart");
    expect(screen.getByRole("link", { name: "نسيت كلمة المرور؟" })).toHaveAttribute("href", "/account/forgot");
  });
});

describe("ForgotPasswordForm", () => {
  it("BR-CU04 sends a reset code and goes to the code step", async () => {
    stubApi({ "POST /api/store/auth/forgot-password": json(200, { data: { otp_sent_via: "sms" } }) });
    const user = userEvent.setup();
    renderWithQuery(<ForgotPasswordForm />);

    await user.type(screen.getByLabelText("رقم الهاتف"), "01012345678");
    await user.click(screen.getByRole("button", { name: "أرسل الكود" }));

    await waitFor(() =>
      expect(router.push).toHaveBeenCalledWith("/account/verify?phone=01012345678&purpose=password_reset&via=sms"),
    );
  });

  it("shows a rate limit", async () => {
    stubApi({
      "POST /api/store/auth/forgot-password": envelopeError(429, "rate_limited", "محاولات كثيرة، حاول لاحقًا"),
    });
    const user = userEvent.setup();
    renderWithQuery(<ForgotPasswordForm />);

    await user.type(screen.getByLabelText("رقم الهاتف"), "01012345678");
    await user.click(screen.getByRole("button", { name: "أرسل الكود" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("محاولات كثيرة، حاول لاحقًا");
  });
});

describe("ResetPasswordForm", () => {
  it("BR-CU04 sets the new password with the verified code, then sends the customer to log in", async () => {
    vi.spyOn(toast, "success").mockImplementation(() => "t");
    rememberReset({ phone: "01012345678", code: "654321" });
    const fetchMock = stubApi({ "POST /api/store/auth/reset-password": json(204) });
    const user = userEvent.setup();
    renderWithQuery(<ResetPasswordForm />);

    await user.type(screen.getByLabelText("كلمة المرور الجديدة"), "newsecret1");
    await user.click(screen.getByRole("button", { name: "حفظ كلمة المرور" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/account/login"));
    expect(callsTo(fetchMock, "POST /api/store/auth/reset-password")[0]?.body).toEqual({
      phone: "01012345678",
      code: "654321",
      password: "newsecret1",
    });
    expect(pendingReset()).toBeNull();
  });

  it("shows an expired code", async () => {
    rememberReset({ phone: "01012345678", code: "654321" });
    stubApi({ "POST /api/store/auth/reset-password": envelopeError(422, "otp_expired", "انتهت صلاحية الكود") });
    const user = userEvent.setup();
    renderWithQuery(<ResetPasswordForm />);

    await user.type(screen.getByLabelText("كلمة المرور الجديدة"), "newsecret1");
    await user.click(screen.getByRole("button", { name: "حفظ كلمة المرور" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("انتهت صلاحية الكود");
  });

  it("without a verified code (a reload) sends the customer back to the start", () => {
    stubApi({});
    renderWithQuery(<ResetPasswordForm />);

    expect(screen.getByRole("link", { name: "استعادة كلمة المرور" })).toHaveAttribute("href", "/account/forgot");
    expect(screen.queryByLabelText("كلمة المرور الجديدة")).not.toBeInTheDocument();
  });
});

describe("InvitationForm", () => {
  const token = "a".repeat(40);

  beforeEach(() => {
    params.value = { token };
  });

  it("BR-CU08 shows who is invited and activates the account with a password", async () => {
    const fetchMock = stubApi({
      [`GET /api/store/auth/invitation/${token}`]: json(200, {
        data: { name: "مكتبة الأمل", phone_masked: "+20101****678", expires_at: "2026-10-17T10:00:00Z" },
      }),
      [`POST /api/store/auth/invitation/${token}`]: json(200, { data: customer() }),
    });
    const user = userEvent.setup();
    renderWithQuery(<InvitationForm />);

    expect(await screen.findByText(/أهلًا مكتبة الأمل/)).toBeInTheDocument();
    expect(screen.getByText("+20101****678")).toBeInTheDocument();
    await user.type(screen.getByLabelText("كلمة المرور"), "secret123");
    await user.click(screen.getByRole("button", { name: "تفعيل الحساب" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/"));
    expect(callsTo(fetchMock, `POST /api/store/auth/invitation/${token}`)[0]?.body).toEqual({ password: "secret123" });
  });

  it("BR-CU08 an expired or used link says so", async () => {
    stubApi({ [`GET /api/store/auth/invitation/${token}`]: envelopeError(404, "not_found", "رابط الدعوة غير صالح") });
    renderWithQuery(<InvitationForm />);

    expect(await screen.findByRole("alert")).toHaveTextContent("رابط الدعوة غير صالح أو منتهي");
  });
});
