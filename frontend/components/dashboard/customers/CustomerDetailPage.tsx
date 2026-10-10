"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { ChevronRightIcon, PencilIcon, TrashIcon } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { toast } from "sonner";

import { TextField } from "@/components/shared/TextField";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import type { DashboardCustomer } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  type CustomerAddressInput,
  useCustomer,
  useCustomerAction,
  useInviteCustomer,
  useStatement,
  useUpdateCustomer,
} from "@/lib/dashboard/customers";
import { pageOf } from "@/lib/dashboard/list-query";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { addressSchema, type AddressFormValues } from "@/lib/forms/address";
import { formatDate, formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";
import { useRouteParam } from "@/lib/store/route";

import { Pager } from "../common/Pager";
import { ReasonDialog } from "../common/ReasonDialog";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { CustomerStatusBadge, inviteMessage } from "./CustomerStatusBadge";

const copy = ar.dashboard.customers;

export function CustomerDetailPage() {
  const id = Number(useRouteParam("id"));
  const customer = useCustomer(id);

  if (customer.isError) {
    return <ErrorBlock error={customer.error} onRetry={() => void customer.refetch()} />;
  }

  if (customer.data === undefined) {
    return <LoadingBlock rows={6} />;
  }

  return <CustomerDetail customer={customer.data} />;
}

export function CustomerDetail({ customer }: { customer: DashboardCustomer }) {
  const [query, setQuery] = useListQuery(["tab"]);
  const tab = query.tab ?? "profile";

  return (
    <>
      <Button asChild variant="link" className="self-start px-0">
        <Link href="/dashboard/customers">
          <ChevronRightIcon className="rtl:rotate-180" />
          {copy.title}
        </Link>
      </Button>
      <PageHeader
        title={customer.name}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <bdi>{formatPhone(customer.phone)}</bdi>
            <CustomerStatusBadge status={customer.status} />
            {customer.outstanding_amount !== "0.00" && (
              <Badge variant="outline">
                {copy.outstanding}: {formatMoney(customer.outstanding_amount)}
              </Badge>
            )}
          </span>
        }
        actions={<CustomerActions customer={customer} />}
      />
      {customer.status === "blocked" && customer.blocked_reason !== null && (
        <Alert variant="destructive">
          <AlertDescription>{copy.blockedReason(customer.blocked_reason)}</AlertDescription>
        </Alert>
      )}
      <Tabs dir="rtl" value={tab} onValueChange={(value) => setQuery({ tab: value === "profile" ? null : value })}>
        <TabsList>
          <TabsTrigger value="profile">{copy.tabs.profile}</TabsTrigger>
          <TabsTrigger value="orders">{copy.tabs.orders}</TabsTrigger>
          <TabsTrigger value="statement">{copy.tabs.statement}</TabsTrigger>
        </TabsList>
        <TabsContent value="profile" className="pt-4">
          {/* Remount with the server's list after a save, so new rows get their ids and are not created twice. */}
          <ProfileForm
            key={`${customer.id}-${customer.name}-${(customer.addresses ?? []).map((address) => address.id).join(",")}`}
            customer={customer}
          />
        </TabsContent>
        <TabsContent value="orders" className="pt-4">
          <RecentOrders customer={customer} />
        </TabsContent>
        <TabsContent value="statement" className="pt-4">
          <Statement customerId={customer.id} />
        </TabsContent>
      </Tabs>
    </>
  );
}

/** The lifecycle actions the current status allows (BR-CU05): invite, approve, block, unblock. */
function CustomerActions({ customer }: { customer: DashboardCustomer }) {
  const invite = useInviteCustomer();
  const act = useCustomerAction(customer.id);
  const run = (action: { action: "approve" } | { action: "unblock" }, done: string) =>
    act.mutate(action, { onSuccess: () => toast.success(done), onError: (error) => toast.error(messageFor(error)) });

  return (
    <>
      {customer.status === "invited" && (
        <Button
          disabled={invite.isPending}
          onClick={() =>
            invite.mutate(customer.id, {
              onSuccess: (sent) => toast.success(inviteMessage(sent)),
              onError: (error) => toast.error(messageFor(error)),
            })
          }
        >
          {copy.invite}
        </Button>
      )}
      {customer.status === "pending_approval" && (
        <Button disabled={act.isPending} onClick={() => run({ action: "approve" }, copy.approved)}>
          {copy.approve}
        </Button>
      )}
      {customer.status === "blocked" ? (
        <Button variant="outline" disabled={act.isPending} onClick={() => run({ action: "unblock" }, copy.unblocked)}>
          {copy.unblock}
        </Button>
      ) : (
        <ReasonDialog
          trigger={(open) => (
            <Button variant="outline" className="text-destructive" onClick={open}>
              {copy.block}
            </Button>
          )}
          title={copy.blockTitle}
          description={copy.blockBody}
          confirmLabel={copy.block}
          destructive
          onSubmit={async (reason) => {
            await act.mutateAsync({ action: "block", reason });
            toast.success(copy.blocked);
          }}
        />
      )}
    </>
  );
}

function addressInput(address: NonNullable<DashboardCustomer["addresses"]>[number]): CustomerAddressInput {
  return {
    id: address.id,
    label: address.label,
    governorate: address.governorate,
    city: address.city,
    address_line: address.address_line,
    landmark: address.landmark,
    phone: address.phone,
    is_default: address.is_default,
  };
}

/** Name and the address list, saved together: the PUT replaces the whole list (D54). The phone is fixed. */
function ProfileForm({ customer }: { customer: DashboardCustomer }) {
  const update = useUpdateCustomer(customer.id);
  const [name, setName] = useState(customer.name);
  const [addresses, setAddresses] = useState<CustomerAddressInput[]>((customer.addresses ?? []).map(addressInput));
  const [editing, setEditing] = useState<number | "new" | null>(null);
  const [error, setError] = useState<string | null>(null);

  const save = () => {
    if (name.trim() === "") {
      setError(ar.validation.required);
      return;
    }

    setError(null);
    update.mutate(
      { name: name.trim(), addresses },
      { onSuccess: () => toast.success(copy.profileSaved), onError: (failure) => setError(messageFor(failure)) },
    );
  };

  return (
    <div className="flex max-w-2xl flex-col gap-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <Label htmlFor="profile-name">{copy.name}</Label>
          <Input id="profile-name" maxLength={150} value={name} onChange={(event) => setName(event.target.value)} />
        </div>
        <div className="flex flex-col gap-1">
          <Label htmlFor="profile-phone">{copy.phone}</Label>
          <Input id="profile-phone" dir="ltr" className="text-start" value={formatPhone(customer.phone)} disabled />
          <p className="text-muted-foreground text-xs">{copy.phoneFixed}</p>
        </div>
      </div>
      <div className="flex items-center justify-between">
        <h2 className="font-medium">{copy.addresses}</h2>
        <Button variant="outline" size="sm" onClick={() => setEditing("new")}>
          {copy.addAddress}
        </Button>
      </div>
      {addresses.length === 0 ? (
        <EmptyBlock>{copy.noAddresses}</EmptyBlock>
      ) : (
        <ul className="flex flex-col gap-2">
          {addresses.map((address, index) => (
            <li
              key={address.id ?? `new-${index}`}
              className="flex items-start justify-between gap-2 rounded-md border p-3 text-sm"
            >
              <div className="flex flex-col gap-0.5">
                <span className="font-medium">
                  {address.label}
                  {address.is_default === true && (
                    <Badge variant="secondary" className="ms-2">
                      {ar.addresses.default}
                    </Badge>
                  )}
                </span>
                <span className="text-muted-foreground">
                  {[address.address_line, address.city, address.governorate].join("، ")}
                </span>
              </div>
              <div className="flex shrink-0 gap-1">
                <Button variant="ghost" size="icon" aria-label={ar.addresses.edit} onClick={() => setEditing(index)}>
                  <PencilIcon />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={copy.removeAddress}
                  onClick={() => setAddresses(addresses.filter((_, at) => at !== index))}
                >
                  <TrashIcon />
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {error !== null && <FieldError role="alert">{error}</FieldError>}
      <Button className="self-start" disabled={update.isPending} onClick={save}>
        {update.isPending ? ar.saving : copy.saveProfile}
      </Button>
      {editing !== null && (
        <AddressDialog
          address={editing === "new" ? null : (addresses[editing] ?? null)}
          onClose={() => setEditing(null)}
          onSave={(saved) => {
            const others =
              saved.is_default === true ? addresses.map((address) => ({ ...address, is_default: false })) : addresses;
            setAddresses(
              editing === "new" ? [...others, saved] : others.map((address, at) => (at === editing ? saved : address)),
            );
            setEditing(null);
          }}
        />
      )}
    </div>
  );
}

function AddressDialog({
  address,
  onClose,
  onSave,
}: {
  address: CustomerAddressInput | null;
  onClose: () => void;
  onSave: (address: CustomerAddressInput) => void;
}) {
  const form = useForm<AddressFormValues>({
    resolver: zodResolver(addressSchema),
    defaultValues: {
      label: address?.label ?? "",
      governorate: address?.governorate ?? "",
      city: address?.city ?? "",
      address_line: address?.address_line ?? "",
      landmark: address?.landmark ?? "",
      phone: "",
      is_default: address?.is_default ?? false,
    },
  });
  const errors = form.formState.errors;

  const submit = form.handleSubmit((values) =>
    onSave({
      id: address?.id,
      ...values,
      landmark: values.landmark === "" ? null : values.landmark,
      phone: values.phone === "" ? (address?.phone ?? null) : values.phone,
    }),
  );

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{address === null ? ar.addresses.add : ar.addresses.edit}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <TextField
            id="address-label"
            label={ar.addresses.label}
            registration={form.register("label")}
            error={errors.label}
          />
          <TextField
            id="address-governorate"
            label={ar.addresses.governorate}
            registration={form.register("governorate")}
            error={errors.governorate}
          />
          <TextField
            id="address-city"
            label={ar.addresses.city}
            registration={form.register("city")}
            error={errors.city}
          />
          <TextField
            id="address-line"
            label={ar.addresses.addressLine}
            registration={form.register("address_line")}
            error={errors.address_line}
          />
          <TextField
            id="address-landmark"
            label={ar.addresses.landmark}
            registration={form.register("landmark")}
            error={errors.landmark}
          />
          <TextField
            id="address-phone"
            label={ar.addresses.phone}
            dir="ltr"
            inputMode="tel"
            registration={form.register("phone")}
            error={errors.phone}
          />
          <Controller
            control={form.control}
            name="is_default"
            render={({ field }) => (
              <div className="flex items-center gap-2">
                <Checkbox
                  id="address-default"
                  checked={field.value}
                  onCheckedChange={(checked) => field.onChange(checked === true)}
                />
                <FieldLabel htmlFor="address-default">{ar.addresses.makeDefault}</FieldLabel>
              </div>
            )}
          />
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit">{ar.dashboard.confirm}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function RecentOrders({ customer }: { customer: DashboardCustomer }) {
  const orders = customer.recent_orders ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{copy.recentOrders}</CardTitle>
        <CardAction>
          <Button asChild variant="link">
            <Link href={`/dashboard/orders?customer_id=${customer.id}`}>{copy.allOrders}</Link>
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent>
        {orders.length === 0 ? (
          <p className="text-muted-foreground text-sm">{copy.noOrders}</p>
        ) : (
          <ul className="flex flex-col divide-y">
            {orders.map((order) => (
              <li key={order.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                <Link href={`/dashboard/orders/${order.number}`} className="font-medium hover:underline">
                  <bdi>#{order.number}</bdi>
                </Link>
                <span>{ar.orders.statuses[order.status]}</span>
                <span className="text-muted-foreground">{formatDateTime(order.placed_at)}</span>
                <span>{formatMoney(order.total)}</span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

/** The statement (08 §10): summary cards and the orders with what is left to collect. */
function Statement({ customerId }: { customerId: number }) {
  const [query, setQuery] = useListQuery(["tab"]);
  const statement = useStatement(customerId, pageOf(query));
  const labels = copy.statement;

  if (statement.isError) {
    return <ErrorBlock error={statement.error} onRetry={() => void statement.refetch()} />;
  }

  if (statement.data === undefined) {
    return <LoadingBlock rows={4} />;
  }

  const { summary } = statement.data;

  return (
    <div className="flex flex-col gap-4">
      <dl className="grid grid-cols-2 gap-3 md:grid-cols-4" data-testid="statement-summary">
        {(
          [
            [labels.ordersCount, String(summary.orders_count)],
            [labels.total, formatMoney(summary.total)],
            [labels.collected, formatMoney(summary.collected)],
            [labels.outstanding, formatMoney(summary.outstanding)],
          ] as const
        ).map(([label, value]) => (
          <div key={label} className="rounded-md border p-3">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-lg font-bold">{value}</dd>
          </div>
        ))}
      </dl>
      {statement.data.data.length === 0 ? (
        <EmptyBlock>{labels.empty}</EmptyBlock>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{labels.order}</TableHead>
                <TableHead>{labels.placedAt}</TableHead>
                <TableHead>{labels.deliveredAt}</TableHead>
                <TableHead>{labels.orderTotal}</TableHead>
                <TableHead>{labels.collected}</TableHead>
                <TableHead>{labels.balance}</TableHead>
                <TableHead>{labels.collection}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {statement.data.data.map((order) => (
                <TableRow key={order.number}>
                  <TableCell>
                    <Link href={`/dashboard/orders/${order.number}`} className="font-medium hover:underline">
                      <bdi>#{order.number}</bdi>
                    </Link>{" "}
                    <span className="text-muted-foreground text-xs">{order.status_label}</span>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">{formatDate(order.placed_at)}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    {order.delivered_at === null ? ar.dashboard.none : formatDate(order.delivered_at)}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">{formatMoney(order.order_total)}</TableCell>
                  <TableCell className="whitespace-nowrap">{formatMoney(order.collected_amount)}</TableCell>
                  <TableCell className="font-medium whitespace-nowrap">{formatMoney(order.balance)}</TableCell>
                  <TableCell>{ar.dashboard.collectionStatuses[order.collection_status]}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={statement.data.meta} onPage={(page) => setQuery({ page })} />
    </div>
  );
}
