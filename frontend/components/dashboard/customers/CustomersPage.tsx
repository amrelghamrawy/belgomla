"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { FieldError } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { CustomerStatus } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { CUSTOMER_LIST_KEYS, useCreateCustomer, useCustomers, useInviteCustomer } from "@/lib/dashboard/customers";
import { activeFilters, hasFilters } from "@/lib/dashboard/list-query";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { phoneField } from "@/lib/forms/phone";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { formatDate } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";

import { FilterPanel } from "../common/FilterPanel";
import { NativeSelect } from "../common/NativeSelect";
import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { CustomerStatusBadge, inviteMessage } from "./CustomerStatusBadge";

const copy = ar.dashboard.customers;
const STATUSES: CustomerStatus[] = ["active", "invited", "pending_approval", "pending_verification", "blocked"];

/** Customers with status and outstanding amount; search, status filter and "new customer". */
export function CustomersPage() {
  const [query, setQuery] = useListQuery(CUSTOMER_LIST_KEYS);
  const customers = useCustomers(query);
  const [q, setQ] = useState(query.q ?? "");
  const [creating, setCreating] = useState(false);

  const search = (event: FormEvent) => {
    event.preventDefault();
    setQuery({ q: q.trim() });
  };

  return (
    <>
      <PageHeader title={copy.title} actions={<Button onClick={() => setCreating(true)}>{copy.add}</Button>} />
      <FilterPanel
        active={activeFilters(query, ["status"])}
        onClear={
          hasFilters(query)
            ? () => {
                setQ("");
                setQuery(Object.fromEntries(Object.keys(query).map((key) => [key, null])));
              }
            : undefined
        }
        leading={
          <form onSubmit={search} role="search" className="flex gap-2">
            <Input
              aria-label={ar.dashboard.list.search}
              placeholder={ar.dashboard.list.searchPlaceholder}
              value={q}
              onChange={(event) => setQ(event.target.value)}
              className="max-w-xs"
            />
            <Button type="submit" variant="outline">
              {ar.dashboard.list.search}
            </Button>
          </form>
        }
      >
        <NativeSelect
          aria-label={copy.status}
          value={query.status ?? ""}
          onChange={(event) => setQuery({ status: event.target.value })}
        >
          <option value="">{copy.allStatuses}</option>
          {STATUSES.map((status) => (
            <option key={status} value={status}>
              {ar.dashboard.customerStatuses[status]}
            </option>
          ))}
        </NativeSelect>
      </FilterPanel>
      {customers.isError && <ErrorBlock error={customers.error} onRetry={() => void customers.refetch()} />}
      {customers.isPending && <LoadingBlock />}
      {customers.data?.data.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {customers.data !== undefined && customers.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.name}</TableHead>
                <TableHead>{copy.phone}</TableHead>
                <TableHead>{copy.status}</TableHead>
                <TableHead>{copy.outstanding}</TableHead>
                <TableHead>{copy.createdAt}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {customers.data.data.map((customer) => (
                <TableRow key={customer.id} data-testid={`customer-${customer.id}`}>
                  <TableCell className="font-medium">
                    <Link href={`/dashboard/customers/${customer.id}`} className="hover:underline">
                      {customer.name}
                    </Link>
                  </TableCell>
                  <TableCell>
                    <bdi>{formatPhone(customer.phone)}</bdi>
                  </TableCell>
                  <TableCell>
                    <CustomerStatusBadge status={customer.status} />
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    {customer.outstanding_amount === "0.00" ? (
                      ar.dashboard.none
                    ) : (
                      <Badge variant="outline">{formatMoney(customer.outstanding_amount)}</Badge>
                    )}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">{formatDate(customer.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={customers.data?.meta} onPage={(page) => setQuery({ page })} />
      {creating && <CreateCustomerDialog onClose={() => setCreating(false)} />}
    </>
  );
}

const createSchema = z.object({
  name: z.string().trim().min(1, ar.validation.required).max(150),
  phone: phoneField(),
  invite: z.boolean(),
});

type CreateValues = z.infer<typeof createSchema>;

/** BR-CU08: create an invited customer, and send the invitation right away unless unticked. */
export function CreateCustomerDialog({ onClose }: { onClose: () => void }) {
  const router = useRouter();
  const create = useCreateCustomer();
  const invite = useInviteCustomer();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<CreateValues>({
    resolver: zodResolver(createSchema),
    defaultValues: { name: "", phone: "", invite: true },
  });
  const errors = form.formState.errors;

  const submit = form.handleSubmit((values) => {
    setError(null);
    create.mutate(
      { name: values.name, phone: values.phone },
      {
        onSuccess: async (customer) => {
          toast.success(copy.created);

          if (values.invite) {
            try {
              const sent = await invite.mutateAsync(customer.id);
              toast.success(inviteMessage(sent));
            } catch (failure) {
              toast.error(messageFor(failure));
            }
          }

          router.push(`/dashboard/customers/${customer.id}`);
        },
        onError: (failure) => {
          if (!applyServerErrors(failure, ["name", "phone"], form.setError)) {
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
          <DialogTitle>{copy.addTitle}</DialogTitle>
          <DialogDescription>{copy.addHint}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <TextField id="customer-name" label={copy.name} registration={form.register("name")} error={errors.name} />
          <TextField
            id="customer-phone"
            label={copy.phone}
            dir="ltr"
            inputMode="tel"
            registration={form.register("phone")}
            error={errors.phone}
          />
          <Controller
            control={form.control}
            name="invite"
            render={({ field }) => (
              <div className="flex items-center gap-2">
                <Checkbox
                  id="customer-invite"
                  checked={field.value}
                  onCheckedChange={(checked) => field.onChange(checked === true)}
                />
                <Label htmlFor="customer-invite">{copy.sendInvite}</Label>
              </div>
            )}
          />
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={create.isPending}>
              {create.isPending ? ar.saving : ar.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
