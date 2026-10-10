import { act, fireEvent, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { OtpVerifyForm, RESEND_SECONDS } from "@/components/store/OtpVerifyForm";
import { pendingReset } from "@/lib/store/reset-flow";
import { verifyHref } from "@/lib/store/verify";

import { customer } from "../fixtures";
import { callsTo, envelopeError, json, stubApi } from "../http";
import { renderWithQuery, router, searchParams } from "../render";

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  router.replace.mockReset();
  router.push.mockReset();
  searchParams.value = new URLSearchParams("phone=01012345678&purpose=register&via=whatsapp");
});

afterEach(() => {
  vi.useRealTimers();
});

const resendButton = () => screen.getByRole("button", { name: /إعادة/ });
const smsButton = () => screen.getByRole("button", { name: "أرسل الكود برسالة SMS بدلًا من واتساب" });

function advance(seconds: number) {
  act(() => vi.advanceTimersByTime(seconds * 1000));
}

async function enterCode(code: string) {
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  await user.type(screen.getByLabelText("كود التحقق"), code);
  await user.click(screen.getByRole("button", { name: "تأكيد" }));
}

describe("verifyHref", () => {
  it("carries the phone, purpose, channel and next page", () => {
    expect(verifyHref({ phone: "01012345678", purpose: "register", via: "sms", next: "/cart" })).toBe(
      "/account/verify?phone=01012345678&purpose=register&via=sms&next=%2Fcart",
    );
  });
});

describe("OtpVerifyForm", () => {
  it("BR-CU07 says where the code was sent", () => {
    stubApi({});
    renderWithQuery(<OtpVerifyForm />);

    expect(screen.getByText("أرسلنا كودًا من 6 أرقام عبر واتساب إلى 01012345678")).toBeInTheDocument();
  });

  it("BR-CU07 locks resend and the SMS link for 60 seconds, counting down", () => {
    stubApi({});
    renderWithQuery(<OtpVerifyForm />);

    expect(resendButton()).toHaveTextContent(`إعادة الإرسال بعد ${RESEND_SECONDS} ث`);
    expect(resendButton()).toBeDisabled();
    expect(smsButton()).toBeDisabled();

    advance(59);
    expect(resendButton()).toHaveTextContent("إعادة الإرسال بعد 1 ث");
    expect(smsButton()).toBeDisabled();

    advance(1);
    expect(resendButton()).toHaveTextContent("إعادة إرسال الكود");
    expect(resendButton()).toBeEnabled();
    expect(smsButton()).toBeEnabled();
  });

  it("BR-CU07 resending sends a new code and restarts the 60 seconds", async () => {
    const fetchMock = stubApi({ "POST /api/store/auth/resend-otp": json(200, { data: { otp_sent_via: "whatsapp" } }) });
    renderWithQuery(<OtpVerifyForm />);
    advance(60);

    fireEvent.click(resendButton());

    await waitFor(() => expect(resendButton()).toHaveTextContent(`إعادة الإرسال بعد ${RESEND_SECONDS} ث`));
    expect(callsTo(fetchMock, "POST /api/store/auth/resend-otp")[0]?.body).toEqual({
      phone: "01012345678",
      purpose: "register",
      channel: "whatsapp",
    });
  });

  it("BR-CU07 'send by SMS' asks for the SMS channel and says so", async () => {
    const fetchMock = stubApi({ "POST /api/store/auth/resend-otp": json(200, { data: { otp_sent_via: "sms" } }) });
    renderWithQuery(<OtpVerifyForm />);
    advance(60);

    fireEvent.click(smsButton());

    expect(await screen.findByText("أرسلنا كودًا من 6 أرقام عبر رسالة SMS إلى 01012345678")).toBeInTheDocument();
    expect(callsTo(fetchMock, "POST /api/store/auth/resend-otp")[0]?.body).toMatchObject({ channel: "sms" });
  });

  it("BR-CU07 a 429 waits for the server's retry_after", async () => {
    stubApi({
      "POST /api/store/auth/resend-otp": envelopeError(429, "rate_limited", "محاولات كثيرة، حاول لاحقًا", {
        retry_after: 120,
      }),
    });
    renderWithQuery(<OtpVerifyForm />);
    advance(60);

    fireEvent.click(resendButton());

    await waitFor(() => expect(resendButton()).toHaveTextContent("إعادة الإرسال بعد 120 ث"));
    expect(screen.getByRole("alert")).toHaveTextContent("محاولات كثيرة، حاول لاحقًا");
  });

  it("BR-CU04 a verified registration logs in and goes to the next page", async () => {
    searchParams.value = new URLSearchParams("phone=01012345678&purpose=register&via=whatsapp&next=/cart");
    const fetchMock = stubApi({ "POST /api/store/auth/verify-otp": json(200, { data: customer() }) });
    renderWithQuery(<OtpVerifyForm />);

    await enterCode("123456");

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/cart"));
    expect(callsTo(fetchMock, "POST /api/store/auth/verify-otp")[0]?.body).toEqual({
      phone: "01012345678",
      code: "123456",
      purpose: "register",
    });
  });

  it("D2 a verified registration waiting for approval is told so", async () => {
    stubApi({ "POST /api/store/auth/verify-otp": json(200, { data: customer({ status: "pending_approval" }) }) });
    renderWithQuery(<OtpVerifyForm />);

    await enterCode("123456");

    expect(await screen.findByText(/حسابك في انتظار موافقة المتجر/)).toBeInTheDocument();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("a password-reset code goes on to the new password, carrying the code in memory", async () => {
    searchParams.value = new URLSearchParams("phone=01012345678&purpose=password_reset&via=whatsapp");
    stubApi({ "POST /api/store/auth/verify-otp": json(204) });
    renderWithQuery(<OtpVerifyForm />);

    await enterCode("654321");

    await waitFor(() => expect(router.push).toHaveBeenCalledWith("/account/reset"));
    expect(pendingReset()).toEqual({ phone: "01012345678", code: "654321" });
  });

  it("shows the server's message for a wrong code", async () => {
    stubApi({
      "POST /api/store/auth/verify-otp": envelopeError(422, "otp_invalid", "الكود غير صحيح، متبقي 4 محاولات", {
        attempts_left: 4,
      }),
    });
    renderWithQuery(<OtpVerifyForm />);

    await enterCode("111111");

    expect(await screen.findByRole("alert")).toHaveTextContent("الكود غير صحيح، متبقي 4 محاولات");
  });

  it("asks for all 6 digits before calling the API", async () => {
    const fetchMock = stubApi({});
    renderWithQuery(<OtpVerifyForm />);

    await enterCode("123");

    expect(await screen.findByRole("alert")).toHaveTextContent("الكود 6 أرقام");
    expect(callsTo(fetchMock, "POST /api/store/auth/verify-otp")).toHaveLength(0);
  });

  it("explains itself when opened without a phone", () => {
    searchParams.value = new URLSearchParams();
    stubApi({});
    renderWithQuery(<OtpVerifyForm />);

    expect(screen.getByText("افتح هذه الصفحة من التسجيل أو استعادة كلمة المرور.")).toBeInTheDocument();
  });
});
