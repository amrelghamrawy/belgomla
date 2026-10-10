import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import { QuantityStepper } from "@/components/store/QuantityStepper";
import type { QuantityBounds } from "@/lib/store/quantity";

function Controlled({
  initial,
  bounds,
  onChange = vi.fn(),
}: {
  initial: number;
  bounds: QuantityBounds;
  onChange?: (n: number) => void;
}) {
  const [value, setValue] = useState(initial);

  return (
    <QuantityStepper
      value={value}
      bounds={bounds}
      onChange={(next) => {
        setValue(next);
        onChange(next);
      }}
    />
  );
}

const quantity = () => screen.getByRole("spinbutton", { name: "الكمية" });

describe("QuantityStepper", () => {
  it("BR-MOQ01 cannot go below the MOQ", async () => {
    const user = userEvent.setup();
    render(<Controlled initial={2} bounds={{ min: 2, max: 20 }} />);

    expect(screen.getByRole("button", { name: "تقليل الكمية" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "زيادة الكمية" }));
    expect(quantity()).toHaveValue(3);
  });

  it("D37 cannot go above what the stock covers", async () => {
    const user = userEvent.setup();
    render(<Controlled initial={19} bounds={{ min: 2, max: 20 }} />);

    await user.click(screen.getByRole("button", { name: "زيادة الكمية" }));

    expect(quantity()).toHaveValue(20);
    expect(screen.getByRole("button", { name: "زيادة الكمية" })).toBeDisabled();
  });

  it.each([
    ["below the MOQ", "1", 2],
    ["above the stock", "500", 20],
    ["inside the bounds", "7", 7],
  ])("clamps a typed quantity %s when the field is left", async (_, typed, expected) => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<Controlled initial={5} bounds={{ min: 2, max: 20 }} onChange={onChange} />);

    await user.clear(quantity());
    await user.type(quantity(), typed);
    await user.tab();

    expect(quantity()).toHaveValue(expected);
    expect(onChange).toHaveBeenLastCalledWith(expected);
  });

  it("applies a typed quantity on Enter", async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<Controlled initial={5} bounds={{ min: 2, max: 20 }} onChange={onChange} />);

    await user.clear(quantity());
    await user.type(quantity(), "9{Enter}");

    expect(onChange).toHaveBeenCalledWith(9);
  });

  it("is fully disabled when asked", () => {
    render(<QuantityStepper value={3} bounds={{ min: 2, max: 20 }} onChange={vi.fn()} disabled />);

    expect(quantity()).toBeDisabled();
    expect(screen.getByRole("button", { name: "زيادة الكمية" })).toBeDisabled();
  });
});
