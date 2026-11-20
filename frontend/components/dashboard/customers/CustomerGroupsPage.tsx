"use client";

import { ArchiveIcon, ArchiveRestoreIcon, PencilIcon } from "lucide-react";
import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { FieldError } from "@/components/ui/field";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { CustomerGroup } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useArchiveCustomerGroup, useCustomerGroups, useSaveCustomerGroup } from "@/lib/dashboard/customer-pricing";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { formatPercent } from "@/lib/format/money";

import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.customerGroups;

/** BR-PR12 (V2 Task 11): the store's customer groups, their % off the base price and members; add, edit, archive. */
export function CustomerGroupsPage() {
  const groups = useCustomerGroups();
  const [editing, setEditing] = useState<CustomerGroup | "new" | null>(null);

  return (
    <>
      <PageHeader
        title={copy.title}
        description={copy.description}
        actions={<Button onClick={() => setEditing("new")}>{copy.add}</Button>}
      />
      {groups.isError && <ErrorBlock error={groups.error} onRetry={() => void groups.refetch()} />}
      {groups.isPending && <LoadingBlock rows={3} />}
      {groups.data?.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {groups.data !== undefined && groups.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table stack>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.name}</TableHead>
                <TableHead>{copy.percent}</TableHead>
                <TableHead>{copy.customers}</TableHead>
                <TableHead>{copy.status}</TableHead>
                <TableHead>
                  <span className="sr-only">{ar.dashboard.edit}</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {groups.data.map((group) => (
                <GroupRow key={group.id} group={group} onEdit={() => setEditing(group)} />
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      {editing !== null && (
        <GroupDialog group={editing === "new" ? undefined : editing} onClose={() => setEditing(null)} />
      )}
    </>
  );
}

function GroupRow({ group, onEdit }: { group: CustomerGroup; onEdit: () => void }) {
  const archive = useArchiveCustomerGroup();
  const save = useSaveCustomerGroup();
  const archived = group.status === "archived";

  return (
    <TableRow data-testid={`group-${group.id}`}>
      <TableCell className="font-medium">{group.name}</TableCell>
      <TableCell className="tabular-nums">{formatPercent(group.discount_percent)}%</TableCell>
      <TableCell>
        <Link href={`/dashboard/customers?group=${group.id}`} className="hover:underline">
          {copy.customersCount(group.customers_count)}
        </Link>
      </TableCell>
      <TableCell>
        <Badge variant={archived ? "secondary" : "outline"}>{archived ? ar.dashboard.archived : copy.active}</Badge>
      </TableCell>
      <TableCell className="flex justify-end gap-1">
        {archived ? (
          <Button
            variant="outline"
            size="sm"
            disabled={save.isPending}
            onClick={() =>
              save.mutate(
                { id: group.id, status: "active" },
                { onSuccess: () => toast.success(copy.restored), onError: (error) => toast.error(messageFor(error)) },
              )
            }
          >
            <ArchiveRestoreIcon aria-hidden />
            {copy.restore}
          </Button>
        ) : (
          <>
            <Button variant="outline" size="sm" onClick={onEdit}>
              <PencilIcon aria-hidden />
              {ar.dashboard.edit}
            </Button>
            <Button
              variant="ghost"
              size="sm"
              disabled={archive.isPending}
              onClick={() =>
                archive.mutate(group.id, {
                  onSuccess: () => toast.success(copy.archived),
                  onError: (error) => toast.error(messageFor(error)),
                })
              }
            >
              <ArchiveIcon aria-hidden />
              {copy.archive}
            </Button>
          </>
        )}
      </TableCell>
    </TableRow>
  );
}

/** Mirrors StoreCustomerGroupRequest: a name up to 100, a % from 0 to 90 with at most 2 decimals, a sort order. */
const groupSchema = z.object({
  name: z.string().trim().min(1, ar.validation.required).max(100),
  discount_percent: z
    .string()
    .trim()
    .regex(/^\d{1,2}(\.\d{1,2})?$/, copy.invalidPercent)
    .refine((value) => Number(value) <= 90, copy.invalidPercent),
  sort_order: z
    .string()
    .trim()
    .regex(/^\d{0,4}$/, ar.validation.required),
});

type GroupValues = z.infer<typeof groupSchema>;

export function GroupDialog({ group, onClose }: { group?: CustomerGroup; onClose: () => void }) {
  const save = useSaveCustomerGroup();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<GroupValues>({
    resolver: zodResolver(groupSchema),
    defaultValues: {
      name: group?.name ?? "",
      discount_percent: group?.discount_percent ?? "",
      sort_order: String(group?.sort_order ?? 0),
    },
  });
  const errors = form.formState.errors;

  const submit = form.handleSubmit((values) => {
    setError(null);
    save.mutate(
      {
        id: group?.id,
        name: values.name,
        discount_percent: values.discount_percent,
        sort_order: values.sort_order === "" ? 0 : Number(values.sort_order),
      },
      {
        onSuccess: () => {
          toast.success(copy.saved);
          onClose();
        },
        onError: (failure) => {
          if (!applyServerErrors(failure, ["name", "discount_percent", "sort_order"], form.setError)) {
            setError(messageFor(failure));
          }
        },
      },
    );
  });

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{group === undefined ? copy.addTitle : copy.editTitle}</DialogTitle>
          <DialogDescription>{copy.description}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <TextField
            id="group-name"
            label={copy.name}
            placeholder={copy.namePlaceholder}
            registration={form.register("name")}
            error={errors.name}
          />
          <TextField
            id="group-percent"
            label={copy.percent}
            dir="ltr"
            inputMode="decimal"
            placeholder={copy.percentHint}
            registration={form.register("discount_percent")}
            error={errors.discount_percent}
          />
          <TextField
            id="group-sort"
            label={copy.sortOrder}
            dir="ltr"
            inputMode="numeric"
            registration={form.register("sort_order")}
            error={errors.sort_order}
          />
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? ar.saving : ar.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
