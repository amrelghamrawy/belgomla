"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiDownload, apiFetch, type DownloadedFile, withQuery } from "@/lib/api/client";
import { saveDownload } from "@/lib/api/download";
import { ApiError } from "@/lib/api/errors";
import { queryKeys } from "@/lib/api/keys";
import type { CatalogImport, ImportPreview, QueuedExport, SpreadsheetFormat } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

import type { ListQuery } from "./list-query";

// Catalog import / export (V2 Task 06, 08 §6): the products file in the import format, its template, the
// preview → import → progress flow, and downloading what the API sends as an attachment.

/** How often a running import is asked for its progress. */
export const IMPORT_POLL_MS = 1500;

/** How often, and how many times, the link of a queued (large) export is tried before giving up (3 minutes). */
export const EXPORT_POLL_MS = 2000;
export const EXPORT_POLL_ATTEMPTS = 90;

/**
 * The export query for the product list's current filters: search, category, status and facets. Paging, sorting
 * and the low-stock switch are list concerns and do not narrow the file.
 */
export function exportParams(query: ListQuery, format: SpreadsheetFormat): Record<string, string> {
  const params: Record<string, string> = { format };

  for (const [key, value] of Object.entries(query)) {
    if (key === "q" || key === "category" || key === "status" || /^f\[.+\]$/.test(key)) {
      params[key] = value;
    }
  }

  return params;
}

const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * Polls the signed link of a queued export until its file is written (`409 export_not_ready` means "not yet").
 * Any other error stops at once; running out of attempts is reported as still not ready.
 */
export async function waitForExport(
  url: string,
  sleep: (ms: number) => Promise<void> = wait,
  attempts = EXPORT_POLL_ATTEMPTS,
): Promise<DownloadedFile> {
  for (let attempt = 0; attempt < attempts; attempt++) {
    await sleep(EXPORT_POLL_MS);

    try {
      const result = await apiDownload(url);

      if ("file" in result) {
        return result.file;
      }
    } catch (error) {
      if (!(error instanceof ApiError && error.code === "export_not_ready")) {
        throw error;
      }
    }
  }

  throw new ApiError(409, "export_not_ready", ar.errors.export_not_ready);
}

/** Downloads the catalog for the list's filters; a large one waits for its job. Resolves to how it went. */
export function useExportProducts() {
  return useMutation({
    mutationFn: async ({ query, format }: { query: ListQuery; format: SpreadsheetFormat }) => {
      const result = await apiDownload<QueuedExport>(
        withQuery("/api/dashboard/products/export", exportParams(query, format)),
      );
      const file = "file" in result ? result.file : await waitForExport(result.queued.download_url);
      saveDownload(file);

      return "file" in result ? ("direct" as const) : ("queued" as const);
    },
  });
}

/** Downloads the empty file with the store's columns. */
export function useImportTemplate() {
  return useMutation({
    mutationFn: async (format: SpreadsheetFormat) => {
      const result = await apiDownload(withQuery("/api/dashboard/products/import/template", { format }));

      if ("file" in result) {
        saveDownload(result.file);
      }
    },
  });
}

/** Uploads a file for the dry run: counts, row errors and the token to import with. Writes nothing. */
export function usePreviewImport() {
  return useMutation({
    mutationFn: (file: File) => {
      const form = new FormData();
      form.append("file", file);

      return apiFetch<ImportPreview>("/api/dashboard/products/import/preview", { method: "POST", body: form });
    },
  });
}

/** Starts the import of a previewed file; the key makes a double tap one run (D55). */
export function useRunImport() {
  return useMutation({
    mutationFn: ({ token, idempotencyKey }: { token: string; idempotencyKey: string }) =>
      apiFetch<CatalogImport>("/api/dashboard/products/import", {
        method: "POST",
        body: { token },
        headers: { "Idempotency-Key": idempotencyKey },
      }),
  });
}

export function isFinished(run: CatalogImport | undefined): boolean {
  return run?.status === "done" || run?.status === "failed";
}

/** The progress of an import, polled until it is done or failed; the product lists are refreshed once it ends. */
export function useImportRun(id: number | null) {
  const queryClient = useQueryClient();

  return useQuery({
    queryKey: queryKeys.dashboardImport(id ?? 0),
    queryFn: async () => {
      const run = await apiFetch<CatalogImport>(`/api/dashboard/imports/${id}`);

      if (isFinished(run)) {
        void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProductsLists });
      }

      return run;
    },
    enabled: id !== null,
    refetchInterval: (query) => (isFinished(query.state.data) ? false : IMPORT_POLL_MS),
  });
}
