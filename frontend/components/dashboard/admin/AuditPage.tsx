"use client";

import { ChevronDownIcon } from "lucide-react";
import { type FormEvent, Fragment, useState } from "react";

import { Button } from "@/components/ui/button";
import { FieldError } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { AuditLog } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { AUDIT_LIST_KEYS, auditDiff, useAuditLog } from "@/lib/dashboard/admin";
import { activeFilters, hasFilters } from "@/lib/dashboard/list-query";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatDateTime } from "@/lib/format/date";
import { cn } from "@/lib/utils";

import { FilterPanel } from "../common/FilterPanel";
import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.audit;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const TEXT_FILTERS = ["entity_type", "entity_id", "actor_id", "batch_id"] as const;
const FILTER_LABELS: Record<(typeof TEXT_FILTERS)[number], string> = {
  entity_type: copy.entityType,
  entity_id: copy.entityId,
  actor_id: copy.actorId,
  batch_id: copy.batchId,
};

/** The audit log (08 §12): filter by table, record, actor, Cairo days and batch; expand a row for old → new. */
export function AuditPage() {
  const [query, setQuery] = useListQuery(AUDIT_LIST_KEYS);
  const audit = useAuditLog(query);
  const [drafts, setDrafts] = useState<Record<string, string>>(() =>
    Object.fromEntries(TEXT_FILTERS.map((key) => [key, query[key] ?? ""])),
  );
  const [problem, setProblem] = useState<string | null>(null);
  const [open, setOpen] = useState<number | null>(null);

  const apply = (event: FormEvent) => {
    event.preventDefault();
    const batch = (drafts.batch_id ?? "").trim();

    if (batch !== "" && !UUID.test(batch)) {
      setProblem(copy.invalidBatch);
      return;
    }

    setProblem(null);
    setQuery(Object.fromEntries(TEXT_FILTERS.map((key) => [key, (drafts[key] ?? "").trim()])));
  };

  return (
    <>
      <PageHeader title={copy.title} description={copy.description} />
      <FilterPanel
        active={activeFilters(query, AUDIT_LIST_KEYS)}
        onClear={
          hasFilters(query)
            ? () => {
                setDrafts({});
                setQuery(Object.fromEntries(Object.keys(query).map((key) => [key, null])));
              }
            : undefined
        }
      >
        <form onSubmit={apply} className="flex flex-wrap items-end gap-2">
          {TEXT_FILTERS.map((key) => (
            <label key={key} className="flex flex-col gap-1 text-xs">
              {FILTER_LABELS[key]}
              <Input
                dir="ltr"
                className={cn("text-start", key === "batch_id" ? "w-80" : "w-32")}
                value={drafts[key] ?? ""}
                onChange={(event) => setDrafts({ ...drafts, [key]: event.target.value })}
              />
            </label>
          ))}
          <label className="flex flex-col gap-1 text-xs">
            {ar.dashboard.list.from}
            <Input type="date" value={query.from ?? ""} onChange={(event) => setQuery({ from: event.target.value })} />
          </label>
          <label className="flex flex-col gap-1 text-xs">
            {ar.dashboard.list.to}
            <Input type="date" value={query.to ?? ""} onChange={(event) => setQuery({ to: event.target.value })} />
          </label>
          <Button type="submit" variant="outline">
            {copy.apply}
          </Button>
        </form>
      </FilterPanel>
      {problem !== null && <FieldError role="alert">{problem}</FieldError>}
      {audit.isError && <ErrorBlock error={audit.error} onRetry={() => void audit.refetch()} />}
      {audit.isPending && <LoadingBlock />}
      {audit.data?.data.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {audit.data !== undefined && audit.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.when}</TableHead>
                <TableHead>{copy.action}</TableHead>
                <TableHead>{copy.entity}</TableHead>
                <TableHead>{copy.actor}</TableHead>
                <TableHead>{copy.batchId}</TableHead>
                <TableHead>
                  <span className="sr-only">{copy.details}</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {audit.data.data.map((entry) => (
                <Fragment key={entry.id}>
                  <TableRow data-testid={`audit-${entry.id}`}>
                    <TableCell className="whitespace-nowrap">{formatDateTime(entry.created_at)}</TableCell>
                    <TableCell>
                      <bdi>{entry.action}</bdi>
                    </TableCell>
                    <TableCell>
                      <bdi>
                        {entry.entity.type}
                        {entry.entity.id === null ? "" : ` #${entry.entity.id}`}
                      </bdi>
                    </TableCell>
                    <TableCell>
                      {copy.actors[entry.actor.type] ?? entry.actor.type}
                      {entry.actor.id === null ? "" : ` #${entry.actor.id}`}
                    </TableCell>
                    <TableCell>
                      {entry.batch_id !== null && (
                        <Button
                          variant="link"
                          size="sm"
                          className="h-auto p-0"
                          onClick={() => {
                            setDrafts({ ...drafts, batch_id: entry.batch_id ?? "" });
                            setQuery({ batch_id: entry.batch_id });
                          }}
                        >
                          {copy.batchFilter}
                        </Button>
                      )}
                    </TableCell>
                    <TableCell>
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={copy.details}
                        aria-expanded={open === entry.id}
                        onClick={() => setOpen(open === entry.id ? null : entry.id)}
                      >
                        <ChevronDownIcon className={cn("transition-transform", open === entry.id && "rotate-180")} />
                      </Button>
                    </TableCell>
                  </TableRow>
                  {open === entry.id && (
                    <TableRow>
                      <TableCell colSpan={6} className="bg-muted/30">
                        <AuditDiff entry={entry} />
                      </TableCell>
                    </TableRow>
                  )}
                </Fragment>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={audit.data?.meta} onPage={(page) => setQuery({ page })} />
    </>
  );
}

/** Old and new values side by side; changed fields stand out. */
export function AuditDiff({ entry }: { entry: AuditLog }) {
  const rows = auditDiff(entry.old_values, entry.new_values);

  if (rows.length === 0) {
    return <p className="text-muted-foreground text-sm">{copy.noChanges}</p>;
  }

  return (
    <table className="w-full text-xs" data-testid="audit-diff">
      <thead>
        <tr className="text-muted-foreground">
          <th className="p-1 text-start font-medium">{copy.field}</th>
          <th className="p-1 text-start font-medium">{copy.before}</th>
          <th className="p-1 text-start font-medium">{copy.after}</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.field} className={cn(row.changed && "bg-amber-50")} data-changed={row.changed}>
            <td className="p-1 font-medium">
              <bdi>{row.field}</bdi>
            </td>
            <td className="text-muted-foreground p-1 break-all">
              <bdi>{row.before ?? ar.dashboard.none}</bdi>
            </td>
            <td className="p-1 break-all">
              <bdi>{row.after ?? ar.dashboard.none}</bdi>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
