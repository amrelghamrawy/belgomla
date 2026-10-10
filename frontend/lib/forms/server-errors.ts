import type { FieldValues, Path, UseFormSetError } from "react-hook-form";

import { isApiError } from "@/lib/api/errors";

/**
 * Puts the backend's per-field messages (`validation_failed` → `error.details`) on the form's fields.
 * Returns true when at least one field got a message, so the caller can skip the general error.
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  fields: readonly Path<T>[],
  setError: UseFormSetError<T>,
): boolean {
  if (!isApiError(error, "validation_failed")) {
    return false;
  }

  let applied = false;

  for (const field of fields) {
    const message = error.fieldMessage(field);

    if (message !== null) {
      setError(field, { type: "server", message });
      applied = true;
    }
  }

  return applied;
}
