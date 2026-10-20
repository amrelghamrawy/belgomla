"use client";

import { DownloadIcon, UploadIcon } from "lucide-react";
import { type FormEvent, useState } from "react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { isApiError } from "@/lib/api/errors";
import type { CatalogImport, ImportPreview, ImportRowError } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  isFinished,
  useImportRun,
  useImportTemplate,
  usePreviewImport,
  useRunImport,
} from "@/lib/dashboard/catalog-files";

const copy = ar.dashboard.importWizard;

/** The file's own problem (unreadable, wrong version, missing columns) when there is one, else the error's message. */
function uploadError(error: unknown): string {
  return isApiError(error, "validation_failed") ? (error.fieldMessage("file") ?? messageFor(error)) : messageFor(error);
}

/**
 * V2 Task 06 import wizard: upload a file → preview of the counts and every row error (row, column, message) →
 * confirm → progress (polls the run) → summary. Nothing is written before the confirm; rows with errors are
 * skipped by the import.
 */
export function ImportWizard() {
  const [open, setOpen] = useState(false);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [runId, setRunId] = useState<number | null>(null);

  const reset = () => {
    setPreview(null);
    setRunId(null);
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);

        if (!next) {
          reset();
        }
      }}
    >
      <Button variant="outline" onClick={() => setOpen(true)}>
        <UploadIcon />
        {ar.dashboard.products.import}
      </Button>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{copy.title}</DialogTitle>
          <DialogDescription>{copy.intro}</DialogDescription>
        </DialogHeader>
        {runId !== null ? (
          <RunStep id={runId} onClose={() => setOpen(false)} />
        ) : preview !== null ? (
          <PreviewStep preview={preview} onBack={reset} onStarted={(run) => setRunId(run.id)} />
        ) : (
          <UploadStep onPreview={setPreview} />
        )}
      </DialogContent>
    </Dialog>
  );
}

function UploadStep({ onPreview }: { onPreview: (preview: ImportPreview) => void }) {
  const upload = usePreviewImport();
  const template = useImportTemplate();
  const [file, setFile] = useState<File | null>(null);

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (file !== null) {
      upload.mutate(file, { onSuccess: onPreview });
    }
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-3">
      <div>
        <Button
          type="button"
          variant="link"
          className="px-0"
          disabled={template.isPending}
          onClick={() => template.mutate("xlsx")}
        >
          <DownloadIcon />
          {copy.template}
        </Button>
      </div>
      <FieldLabel htmlFor="import-file">{copy.file}</FieldLabel>
      <Input
        id="import-file"
        type="file"
        accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        onChange={(event) => setFile(event.target.files?.[0] ?? null)}
      />
      {upload.isError && <FieldError role="alert">{uploadError(upload.error)}</FieldError>}
      {template.isError && <FieldError role="alert">{messageFor(template.error)}</FieldError>}
      <DialogFooter>
        <Button type="submit" disabled={file === null || upload.isPending}>
          {upload.isPending ? copy.checking : copy.check}
        </Button>
      </DialogFooter>
    </form>
  );
}

function PreviewStep({
  preview,
  onBack,
  onStarted,
}: {
  preview: ImportPreview;
  onBack: () => void;
  onStarted: (run: CatalogImport) => void;
}) {
  const run = useRunImport();
  const idempotency = useIdempotencyKey("import");
  const importable = preview.create + preview.update;

  const start = () => {
    const body = { token: preview.token };
    run.mutate({ ...body, idempotencyKey: idempotency.keyFor(body) }, { onSuccess: onStarted });
  };

  return (
    <div className="flex flex-col gap-3" data-testid="import-preview">
      <Counts
        items={[
          [copy.counts.total, preview.total],
          [copy.counts.create, preview.create],
          [copy.counts.update, preview.update],
          [copy.counts.skip, preview.skip],
        ]}
      />
      {preview.errors.length === 0 ? (
        <p className="text-sm text-emerald-700 dark:text-emerald-400">{copy.noErrors}</p>
      ) : (
        <>
          <p className="text-muted-foreground text-sm">{copy.skipNote}</p>
          <RowErrors errors={preview.errors} />
          {preview.errors_truncated && <p className="text-muted-foreground text-xs">{copy.truncated}</p>}
        </>
      )}
      {run.isError && <FieldError role="alert">{messageFor(run.error)}</FieldError>}
      <DialogFooter>
        <Button variant="outline" onClick={onBack}>
          {copy.another}
        </Button>
        <Button disabled={importable === 0 || run.isPending} onClick={start}>
          {run.isPending ? copy.starting : importable === 0 ? copy.nothingToImport : copy.confirm(importable)}
        </Button>
      </DialogFooter>
    </div>
  );
}

function RunStep({ id, onClose }: { id: number; onClose: () => void }) {
  const run = useImportRun(id);
  const data = run.data;
  const percent = data && data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;

  if (run.isError) {
    return <FieldError role="alert">{messageFor(run.error)}</FieldError>;
  }

  if (data === undefined || !isFinished(data)) {
    return (
      <div className="flex flex-col gap-2" data-testid="import-progress" aria-live="polite">
        <p className="text-sm">{data && data.total > 0 ? copy.progress(data.processed, data.total) : copy.queued}</p>
        <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
          <div className="bg-primary h-full transition-all" style={{ width: `${percent}%` }} />
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3" data-testid="import-summary">
      <p role="status" className="font-medium">
        {data.status === "done" ? copy.done : copy.failed}
      </p>
      <Counts
        items={[
          [copy.result.created, data.created],
          [copy.result.updated, data.updated],
          [copy.result.skipped, data.skipped],
          [copy.result.failed, data.failed],
        ]}
      />
      {data.errors.length > 0 && <RowErrors errors={data.errors} />}
      <DialogFooter>
        <Button onClick={onClose}>{copy.close}</Button>
      </DialogFooter>
    </div>
  );
}

function Counts({ items }: { items: [string, number][] }) {
  return (
    <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
      {items.map(([label, value]) => (
        <div key={label} className="rounded-md border p-2">
          <dt className="text-muted-foreground text-xs">{label}</dt>
          <dd className="text-lg font-bold">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

function RowErrors({ errors }: { errors: ImportRowError[] }) {
  return (
    <div className="max-h-64 overflow-auto rounded-md border" data-testid="import-errors">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{copy.row}</TableHead>
            <TableHead>{copy.column}</TableHead>
            <TableHead>{copy.message}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {errors.map((error, index) => (
            <TableRow key={`${error.row}-${error.column}-${index}`}>
              <TableCell>{error.row === 0 ? "—" : error.row}</TableCell>
              <TableCell className="whitespace-nowrap">{error.label}</TableCell>
              <TableCell className="min-w-48 whitespace-normal">{error.message}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
