import { describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";
import { applyServerErrors } from "@/lib/forms/server-errors";

type Values = { email: string; password: string };

describe("applyServerErrors", () => {
  it("puts each field's first server message on the form", () => {
    const setError = vi.fn();
    const error = new ApiError(422, "validation_failed", "راجع البيانات", { password: ["كلمة المرور مطلوب.", "x"] });

    expect(applyServerErrors<Values>(error, ["email", "password"], setError)).toBe(true);
    expect(setError).toHaveBeenCalledTimes(1);
    expect(setError).toHaveBeenCalledWith("password", { type: "server", message: "كلمة المرور مطلوب." });
  });

  it("ignores details for fields the form does not have", () => {
    const setError = vi.fn();
    const error = new ApiError(422, "validation_failed", "راجع البيانات", { token: ["x"] });

    expect(applyServerErrors<Values>(error, ["email", "password"], setError)).toBe(false);
    expect(setError).not.toHaveBeenCalled();
  });

  it("does nothing for errors that are not validation errors", () => {
    const setError = vi.fn();

    expect(applyServerErrors<Values>(new ApiError(422, "invalid_credentials", "x"), ["email"], setError)).toBe(false);
    expect(setError).not.toHaveBeenCalled();
  });
});
