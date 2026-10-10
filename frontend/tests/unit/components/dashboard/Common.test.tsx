import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { Pager } from "@/components/dashboard/common/Pager";
import { ReasonDialog } from "@/components/dashboard/common/ReasonDialog";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api/errors";

import { page } from "../../fixtures";

function renderDialog(onSubmit: (reason: string) => Promise<unknown>) {
  render(
    <ReasonDialog
      trigger={(open) => <Button onClick={open}>إلغاء الطلب</Button>}
      title="إلغاء الطلب"
      confirmLabel="تأكيد الإلغاء"
      destructive
      onSubmit={onSubmit}
    />,
  );
}

describe("ReasonDialog", () => {
  it("requires a reason before calling the action", async () => {
    const onSubmit = vi.fn(async () => undefined);
    const user = userEvent.setup();
    renderDialog(onSubmit);

    await user.click(screen.getByRole("button", { name: "إلغاء الطلب" }));
    await user.click(screen.getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(screen.getByRole("alert")).toHaveTextContent("اكتب السبب");
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("sends the trimmed reason and closes on success", async () => {
    const onSubmit = vi.fn(async () => undefined);
    const user = userEvent.setup();
    renderDialog(onSubmit);

    await user.click(screen.getByRole("button", { name: "إلغاء الطلب" }));
    await user.type(screen.getByLabelText("السبب"), "  العميل اعتذر  ");
    await user.click(screen.getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(onSubmit).toHaveBeenCalledWith("العميل اعتذر");
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
  });

  it("keeps the dialog open and shows the server message when the action fails", async () => {
    const failure = new ApiError(
      409,
      "has_collections",
      "لا يمكن إلغاء طلب عليه تحصيلات، ألغِ التحصيلات أولًا",
      null,
      null,
    );
    const user = userEvent.setup();
    renderDialog(async () => Promise.reject(failure));

    await user.click(screen.getByRole("button", { name: "إلغاء الطلب" }));
    await user.type(screen.getByLabelText("السبب"), "خطأ");
    await user.click(screen.getByRole("button", { name: "تأكيد الإلغاء" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("لا يمكن إلغاء طلب عليه تحصيلات");
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });
});

describe("Pager", () => {
  it("shows the total and moves between pages", async () => {
    const onPage = vi.fn();
    const user = userEvent.setup();
    render(<Pager meta={page([], { current_page: 2, last_page: 3, total: 60 }).meta} onPage={onPage} />);

    expect(screen.getByText("60 نتيجة")).toBeInTheDocument();
    expect(screen.getByText("صفحة 2 من 3")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "السابق" }));
    await user.click(screen.getByRole("button", { name: "التالي" }));

    expect(onPage.mock.calls).toEqual([[1], [3]]);
  });

  it("disables the edges and hides the buttons for a single page", () => {
    const { rerender } = render(
      <Pager meta={page([], { current_page: 1, last_page: 2, total: 30 }).meta} onPage={vi.fn()} />,
    );

    expect(screen.getByRole("button", { name: "السابق" })).toBeDisabled();

    rerender(<Pager meta={page([], { total: 3 }).meta} onPage={vi.fn()} />);

    expect(screen.queryByRole("button", { name: "التالي" })).not.toBeInTheDocument();
  });
});
