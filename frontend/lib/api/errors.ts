import type { ErrorCode } from "./types";

/** Codes the client produces itself, on top of the backend's `error.code` values. */
export type ClientErrorCode = "network_error";

export type ApiErrorCode = ErrorCode | ClientErrorCode;

/** Per-field messages on `validation_failed`, or extra data on some codes (`attempts_left`, `retry_after`). */
export type ErrorDetails = Record<string, unknown>;

/** A failed API call, built from the error envelope (08-api-contract.md §0). */
export class ApiError extends Error {
  override readonly name: string = "ApiError";

  constructor(
    readonly status: number,
    readonly code: ApiErrorCode,
    message: string,
    readonly details: ErrorDetails | null = null,
    readonly items: Record<string, unknown>[] | null = null,
  ) {
    super(message);
  }

  /** The first message for a field on `validation_failed`, e.g. `fieldMessage('password')`. */
  fieldMessage(field: string): string | null {
    const messages = this.details?.[field];

    return Array.isArray(messages) && typeof messages[0] === "string" ? messages[0] : null;
  }
}

/** `503 store_unavailable` (BR-T07): the whole storefront shows «المتجر غير متاح». */
export class StoreUnavailableError extends ApiError {
  override readonly name = "StoreUnavailableError";
}

export function isApiError(error: unknown, code?: ApiErrorCode): error is ApiError {
  return error instanceof ApiError && (code === undefined || error.code === code);
}
