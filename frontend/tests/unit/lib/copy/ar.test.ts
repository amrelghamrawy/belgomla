import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

import { ApiError } from "@/lib/api/errors";
import { ar, messageFor } from "@/lib/copy/ar";

const spec = JSON.parse(readFileSync(resolve(__dirname, "../../../../../backend/openapi.json"), "utf8")) as {
  components: { schemas: { ErrorCode: { enum: string[] } } };
};

describe("error messages", () => {
  it("has an Arabic fallback for every error code in the API spec, and nothing else", () => {
    const codes = [...spec.components.schemas.ErrorCode.enum, "network_error"].sort();

    expect(Object.keys(ar.errors).sort()).toEqual(codes);
  });

  it("prefers the backend's Arabic message", () => {
    const error = new ApiError(422, "invalid_credentials", "بيانات الدخول غير صحيحة من الخادم");

    expect(messageFor(error)).toBe("بيانات الدخول غير صحيحة من الخادم");
  });

  it("falls back to the code's message when the backend sent none", () => {
    expect(messageFor(new ApiError(422, "otp_expired", ""))).toBe(ar.errors.otp_expired);
  });

  it.each(["server_error", "http_error"] as const)("uses its own text for generic %s responses", (code) => {
    expect(messageFor(new ApiError(500, code, "Method Not Allowed"))).toBe(ar.errors[code]);
  });

  it("shows the server error text for anything that is not an API error", () => {
    expect(messageFor(new Error("boom"))).toBe(ar.errors.server_error);
  });
});
