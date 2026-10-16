import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { StoreWhatsAppBanner, StoreWhatsAppCard } from "@/components/dashboard/admin/StoreWhatsAppCard";
import { needsLink, needsRelink, PAIRING_POLL_MS } from "@/lib/dashboard/store-whatsapp";

import { storeWhatsApp } from "../../dashboard-fixtures";
import { callsTo, envelopeError, json, stubApi } from "../../http";
import { pathname, renderWithQuery } from "../../render";

const PATH = "/api/dashboard/settings/whatsapp";
const QR = "data:image/png;base64,iVBORw0KGgo=";

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
  vi.spyOn(toast, "warning").mockImplementation(() => "t");
});

describe("StoreWhatsAppCard", () => {
  it("renders nothing while the platform has the feature off", async () => {
    const fetchMock = stubApi({ [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ enabled: false }) }) });

    const { container } = renderWithQuery(<StoreWhatsAppCard />);

    await waitFor(() => expect(callsTo(fetchMock, `GET ${PATH}`)).toHaveLength(1));
    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it("explains the link before a number is linked and starts pairing on click", async () => {
    const fetchMock = stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp() }),
      [`POST ${PATH}`]: json(200, { data: storeWhatsApp({ status: "pairing", qr: QR }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<StoreWhatsAppCard />);

    expect(await screen.findByText("استخدم رقم واتساب للأعمال خاصًا بالمتجر.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "ربط رقم واتساب" }));

    expect(await screen.findByRole("img", { name: "رمز ربط واتساب" })).toHaveAttribute("src", QR);
    expect(callsTo(fetchMock, `POST ${PATH}`)).toHaveLength(1);
  });

  it("keeps the qr box while the gateway prepares the code", async () => {
    stubApi({ [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ status: "pairing" }) }) });

    renderWithQuery(<StoreWhatsAppCard />);

    expect(await screen.findByText("جارٍ تجهيز الرمز…")).toBeInTheDocument();
  });

  it("re-reads the status while pairing and shows the number once scanned", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const fetchMock = stubApi({
      [`GET ${PATH}`]: [
        json(200, { data: storeWhatsApp({ status: "pairing", qr: QR }) }),
        json(200, {
          data: storeWhatsApp({ status: "connected", phone: "+201001234567", connected_at: "2026-10-16T10:00:00Z" }),
        }),
      ],
    });
    renderWithQuery(<StoreWhatsAppCard />);
    await screen.findByRole("img", { name: "رمز ربط واتساب" });

    await vi.advanceTimersByTimeAsync(PAIRING_POLL_MS);

    expect(await screen.findByText("+201001234567")).toBeInTheDocument();
    expect(callsTo(fetchMock, `GET ${PATH}`)).toHaveLength(2);
    vi.useRealTimers();
  });

  it("does not poll once the number is connected", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const fetchMock = stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ status: "connected", phone: "+201001234567" }) }),
    });
    renderWithQuery(<StoreWhatsAppCard />);
    await screen.findByText("+201001234567");

    await vi.advanceTimersByTimeAsync(PAIRING_POLL_MS * 3);

    expect(callsTo(fetchMock, `GET ${PATH}`)).toHaveLength(1);
    vi.useRealTimers();
  });

  it("asks for confirmation, unlinks and tells the merchant to remove the device when the phone was not told", async () => {
    const fetchMock = stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ status: "connected", phone: "+201001234567" }) }),
      [`DELETE ${PATH}`]: json(200, {
        data: storeWhatsApp({ disconnect_reason: "unlinked", logged_out_on_phone: false }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<StoreWhatsAppCard />);

    await user.click(await screen.findByRole("button", { name: "فصل الرقم" }));
    expect(callsTo(fetchMock, `DELETE ${PATH}`)).toHaveLength(0);
    await user.click(screen.getByRole("button", { name: "تأكيد الفصل" }));

    await waitFor(() =>
      expect(toast.warning).toHaveBeenCalledWith(
        "تعذّر إخطار الهاتف: احذف «BelGomla» من الأجهزة المرتبطة في واتساب يدويًا.",
      ),
    );
    expect(await screen.findByRole("button", { name: "ربط رقم واتساب" })).toBeInTheDocument();
  });

  it("confirms an unlink the phone was told about", async () => {
    stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ status: "connected", phone: "+201001234567" }) }),
      [`DELETE ${PATH}`]: json(200, {
        data: storeWhatsApp({ disconnect_reason: "unlinked", logged_out_on_phone: true }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<StoreWhatsAppCard />);

    await user.click(await screen.findByRole("button", { name: "فصل الرقم" }));
    await user.click(screen.getByRole("button", { name: "تأكيد الفصل" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("تم فصل الرقم"));
  });

  it("keeps the number when the merchant changes their mind", async () => {
    const fetchMock = stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ status: "connected", phone: "+201001234567" }) }),
    });
    const user = userEvent.setup();
    renderWithQuery(<StoreWhatsAppCard />);

    await user.click(await screen.findByRole("button", { name: "فصل الرقم" }));
    await user.click(screen.getByRole("button", { name: "إبقاء الرقم" }));

    expect(screen.getByRole("button", { name: "فصل الرقم" })).toBeInTheDocument();
    expect(callsTo(fetchMock, `DELETE ${PATH}`)).toHaveLength(0);
  });

  it("cancels a pairing", async () => {
    const fetchMock = stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ status: "pairing", qr: QR }) }),
      [`DELETE ${PATH}`]: json(200, {
        data: storeWhatsApp({ disconnect_reason: "unlinked", logged_out_on_phone: false }),
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<StoreWhatsAppCard />);

    await user.click(await screen.findByRole("button", { name: "إلغاء الربط" }));

    await waitFor(() => expect(callsTo(fetchMock, `DELETE ${PATH}`)).toHaveLength(1));
    expect(toast.success).toHaveBeenCalledWith("تم فصل الرقم");
  });

  it("shows why a dropped number dropped and offers to link it again", async () => {
    stubApi({
      [`GET ${PATH}`]: json(200, {
        data: storeWhatsApp({ status: "logged_out", phone: "+201001234567", disconnect_reason: "logged_out" }),
      }),
    });

    renderWithQuery(<StoreWhatsAppCard />);

    expect(await screen.findByText("تم تسجيل الخروج من الهاتف.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "ربط الرقم من جديد" })).toBeInTheDocument();
  });

  it("tells the merchant an expired qr can be tried again", async () => {
    stubApi({ [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ disconnect_reason: "qr_expired" }) }) });

    renderWithQuery(<StoreWhatsAppCard />);

    expect(await screen.findByText("انتهت صلاحية الرمز قبل مسحه، حاول مرة أخرى.")).toBeInTheDocument();
  });

  it("falls back to a generic reason it does not know", async () => {
    stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ status: "logged_out", disconnect_reason: "something_new" }) }),
    });

    renderWithQuery(<StoreWhatsAppCard />);

    expect(await screen.findByText("انقطع الاتصال بواتساب.")).toBeInTheDocument();
  });

  it("shows the server error when the gateway refuses the link", async () => {
    stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp() }),
      [`POST ${PATH}`]: envelopeError(503, "wa_gateway_unavailable", "خدمة ربط واتساب غير متاحة الآن، حاول بعد قليل"),
    });
    const user = userEvent.setup();
    renderWithQuery(<StoreWhatsAppCard />);

    await user.click(await screen.findByRole("button", { name: "ربط رقم واتساب" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("خدمة ربط واتساب غير متاحة الآن، حاول بعد قليل");
  });

  it("offers a retry when the status cannot be read", async () => {
    stubApi({ [`GET ${PATH}`]: envelopeError(500, "server_error", "خطأ") });

    renderWithQuery(<StoreWhatsAppCard />);

    expect(await screen.findByRole("button", { name: "حاول مرة أخرى" })).toBeInTheDocument();
  });
});

describe("StoreWhatsAppBanner", () => {
  beforeEach(() => {
    pathname.value = "/dashboard/orders";
  });

  it("warns on every page while the number is dropped and opens the settings", async () => {
    stubApi({
      [`GET ${PATH}`]: json(200, { data: storeWhatsApp({ status: "logged_out", disconnect_reason: "logged_out" }) }),
    });

    renderWithQuery(<StoreWhatsAppBanner />);

    const banner = await screen.findByTestId("store-whatsapp-banner");
    expect(banner).toHaveTextContent("انفصل رقم واتساب المتجر، والرسائل تُرسل الآن من رقم المنصة.");
    expect(banner).toHaveAttribute("href", "/dashboard/settings");
  });

  it.each([
    ["never linked", storeWhatsApp()],
    ["unlinked", storeWhatsApp({ disconnect_reason: "unlinked" })],
    ["pairing", storeWhatsApp({ status: "pairing" })],
  ])("reminds to link the number while it is %s, and the whole bar opens the settings", async (_name, status) => {
    stubApi({ [`GET ${PATH}`]: json(200, { data: status }) });

    renderWithQuery(<StoreWhatsAppBanner />);

    const banner = await screen.findByRole("link", { name: /رقم واتساب المتجر غير مربوط/ });
    expect(banner).toHaveTextContent("اربط رقمك من الإعدادات");
    expect(banner).toHaveAttribute("href", "/dashboard/settings");
  });

  it.each([
    ["the number is connected", storeWhatsApp({ status: "connected" }), "/dashboard/orders"],
    ["the feature is off", storeWhatsApp({ enabled: false }), "/dashboard/orders"],
    ["on the settings page, which has the card", storeWhatsApp(), "/dashboard/settings"],
  ])("stays hidden when %s", async (_name, status, path) => {
    pathname.value = path;
    const fetchMock = stubApi({ [`GET ${PATH}`]: json(200, { data: status }) });

    renderWithQuery(<StoreWhatsAppBanner />);

    await waitFor(() => expect(callsTo(fetchMock, `GET ${PATH}`)).toHaveLength(1));
    expect(screen.queryByTestId("store-whatsapp-banner")).not.toBeInTheDocument();
  });
});

describe("needsLink", () => {
  it.each([
    ["never linked", storeWhatsApp(), true],
    ["pairing", storeWhatsApp({ status: "pairing" }), true],
    ["logged out", storeWhatsApp({ status: "logged_out" }), true],
    ["connected", storeWhatsApp({ status: "connected" }), false],
    ["feature off", storeWhatsApp({ enabled: false }), false],
    ["unknown", undefined, false],
  ])("is %s → %s", (_name, status, expected) => {
    expect(needsLink(status)).toBe(expected);
  });
});

describe("needsRelink", () => {
  it.each([
    ["logged out", storeWhatsApp({ status: "logged_out" }), true],
    ["refused", storeWhatsApp({ status: "error" }), true],
    ["connected", storeWhatsApp({ status: "connected" }), false],
    ["never linked", storeWhatsApp(), false],
    ["feature off", storeWhatsApp({ enabled: false, status: "logged_out" }), false],
    ["unknown", undefined, false],
  ])("is %s → %s", (_name, status, expected) => {
    expect(needsRelink(status)).toBe(expected);
  });
});
