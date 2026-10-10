import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { toast } from "sonner";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { addressSchema } from "@/components/store/AddressFormDialog";
import { AddressList } from "@/components/store/AddressList";

import { address, customer } from "../fixtures";
import { callsTo, json, stubApi } from "../http";
import { renderWithQuery } from "../render";

beforeEach(() => {
  vi.spyOn(toast, "success").mockImplementation(() => "t");
});

const valid = {
  label: "المكتبة",
  governorate: "القاهرة",
  city: "مدينة نصر",
  address_line: "12 شارع",
  landmark: "",
  phone: "",
  is_default: false,
};

describe("addressSchema", () => {
  it.each([
    ["label", { label: "" }],
    ["label over 50", { label: "x".repeat(51) }],
    ["city over 80", { city: "x".repeat(81) }],
    ["address line over 300", { address_line: "x".repeat(301) }],
    ["a wrong phone", { phone: "0123" }],
  ])("BR-CU03 rejects %s", (_, override) => {
    expect(addressSchema.safeParse({ ...valid, ...override }).success).toBe(false);
  });

  it("accepts an empty optional phone and landmark", () => {
    expect(addressSchema.safeParse(valid).success).toBe(true);
  });
});

describe("AddressList", () => {
  function stubAddresses(routes: Parameters<typeof stubApi>[0] = {}) {
    return stubApi({
      "GET /api/store/me": json(200, { data: customer() }),
      "GET /api/store/addresses": json(200, {
        data: [address(), address({ id: 32, label: "المخزن", is_default: false })],
      }),
      ...routes,
    });
  }

  it("BR-CU03 lists the addresses and marks the default", async () => {
    stubAddresses();
    renderWithQuery(<AddressList />);

    const items = await screen.findAllByRole("listitem");
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent("افتراضي");
    expect(items[1]).not.toHaveTextContent("افتراضي");
  });

  it("edits an address and can make it the default", async () => {
    const fetchMock = stubAddresses({ "PUT /api/store/addresses/32": json(200, { data: address({ id: 32 }) }) });
    const user = userEvent.setup();
    renderWithQuery(<AddressList />);

    await user.click((await screen.findAllByRole("button", { name: "تعديل العنوان" }))[1] as HTMLElement);
    const dialog = await screen.findByRole("dialog", { name: "تعديل العنوان" });
    expect(within(dialog).getByLabelText("اسم العنوان")).toHaveValue("المخزن");
    await user.click(within(dialog).getByRole("checkbox", { name: "اجعله العنوان الافتراضي" }));
    await user.click(within(dialog).getByRole("button", { name: "حفظ" }));

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(callsTo(fetchMock, "PUT /api/store/addresses/32")[0]?.body).toMatchObject({
      label: "المخزن",
      is_default: true,
    });
  });

  it("maps the backend's field errors into the form", async () => {
    stubAddresses({
      "POST /api/store/addresses": json(422, {
        error: { code: "validation_failed", message: "راجع البيانات", details: { city: ["المدينة غير معروفة"] } },
      }),
    });
    const user = userEvent.setup();
    renderWithQuery(<AddressList />);

    await user.click(await screen.findByRole("button", { name: "إضافة عنوان" }));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("اسم العنوان"), "الفرع");
    await user.type(within(dialog).getByLabelText("المحافظة"), "الجيزة");
    await user.type(within(dialog).getByLabelText("المدينة / المنطقة"), "؟");
    await user.type(within(dialog).getByLabelText("العنوان بالتفصيل"), "5 شارع");
    await user.click(within(dialog).getByRole("button", { name: "حفظ" }));

    expect(await within(dialog).findByText("المدينة غير معروفة")).toBeInTheDocument();
  });

  it("deletes an address after confirming", async () => {
    const fetchMock = stubAddresses({ "DELETE /api/store/addresses/32": json(204) });
    const user = userEvent.setup();
    renderWithQuery(<AddressList />);

    await user.click((await screen.findAllByRole("button", { name: "حذف" }))[1] as HTMLElement);
    const dialog = await screen.findByRole("dialog", { name: "حذف هذا العنوان؟" });
    await user.click(within(dialog).getByRole("button", { name: "حذف" }));

    await waitFor(() => expect(callsTo(fetchMock, "DELETE /api/store/addresses/32")).toHaveLength(1));
  });
});
