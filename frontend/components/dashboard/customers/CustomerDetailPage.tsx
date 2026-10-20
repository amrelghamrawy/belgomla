"use client";

import { ChevronRightIcon, PencilIcon, TrashIcon } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { LedgerTable } from "@/components/shared/LedgerTable";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import type { DashboardCustomer } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  type CustomerAddressInput,
  useCustomer,
  useCustomerAction,
  useInviteCustomer,
  STATEMENT_KEYS,
  useCustomerCredits,
  useStatement,
  useUpdateCustomer,
  useVoidCredit,
} from "@/lib/dashboard/customers";
import { pageOf } from "@/lib/dashboard/list-query";
import { toPiasters } from "@/lib/dashboard/price-math";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";
import { useRouteParam } from "@/lib/store/route";
import { useCan } from "@/lib/dashboard/permissions";

import { Pager } from "../common/Pager";
import { ReasonDialog } from "../common/ReasonDialog";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { AddressDialog, addressInput } from "./AddressDialog";
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
  const allowed = useCan();

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
            {customer.credit_limit !== null && (
              <Badge variant="outline">
                {copy.creditLimit}: {formatMoney(customer.credit_limit)}
              </Badge>
            )}
            {customer.payment_terms_days !== null && (
              <Badge variant="outline">{copy.termsDays(customer.payment_terms_days)}</Badge>
            )}
            {customer.outstanding_amount !== "0.00" && (
              <Badge variant="outline">
                {copy.outstanding}: {formatMoney(customer.outstanding_amount)}
              </Badge>
            )}
          </span>
        }
        actions={
          <>
            {allowed("orders.create") && customer.status === "active" && (
              <Button asChild variant="outline">
                <Link href={`/dashboard/orders/new?customer=${customer.id}`}>{copy.newOrderFor}</Link>
              </Button>
            )}
            {allowed("customers.manage") && <CustomerActions customer={customer} />}
          </>
        }
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
            key={`${customer.id}-${customer.name}-${customer.credit_limit}-${customer.payment_terms_days}-${(customer.addresses ?? []).map((address) => address.id).join(",")}`}
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

/** BR-C05, BR-C06: the typed limit and terms as the request sends them (blank = none), or null when not valid. */
export function creditTerms(
  limit: string,
  terms: string,
): { credit_limit: string | null; payment_terms_days: number | null } | null {
  const cleanLimit = limit.trim();
  const cleanTerms = terms.trim();

  if (
    (cleanLimit !== "" && toPiasters(cleanLimit) === null) ||
    (cleanTerms !== "" && !/^\d{1,3}$/.test(cleanTerms)) ||
    Number(cleanTerms) > 365
  ) {
    return null;
  }

  return {
    credit_limit: cleanLimit === "" ? null : cleanLimit,
    payment_terms_days: cleanTerms === "" ? null : Number(cleanTerms),
  };
}

/**
 * Name and the address list, saved together: the PUT replaces the whole list (D54). The phone is fixed. D82: the name
 * and addresses need `customers.manage`, the credit terms `customers.credit`; what the role may not change is shown
 * read-only and left out of the PUT.
 */
function ProfileForm({ customer }: { customer: DashboardCustomer }) {
  const update = useUpdateCustomer(customer.id);
  const allowed = useCan();
  const editsProfile = allowed("customers.manage");
  const editsCredit = allowed("customers.credit");
  const [name, setName] = useState(customer.name);
  const [addresses, setAddresses] = useState<CustomerAddressInput[]>((customer.addresses ?? []).map(addressInput));
  const [creditLimit, setCreditLimit] = useState(customer.credit_limit ?? "");
  const [terms, setTerms] = useState(customer.payment_terms_days === null ? "" : String(customer.payment_terms_days));
  const [editing, setEditing] = useState<number | "new" | null>(null);
  const [error, setError] = useState<string | null>(null);

  const save = () => {
    if (editsProfile && name.trim() === "") {
      setError(ar.validation.required);
      return;
    }

    const termsBody = editsCredit ? creditTerms(creditLimit, terms) : {};

    if (termsBody === null) {
      setError(ar.dashboard.productForm.invalidMoney);
      return;
    }

    setError(null);
    update.mutate(
      editsProfile ? { name: name.trim(), addresses, ...termsBody } : { name: customer.name, ...termsBody },
      { onSuccess: () => toast.success(copy.profileSaved), onError: (failure) => setError(messageFor(failure)) },
    );
  };

  return (
    <div className="flex max-w-2xl flex-col gap-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <Label htmlFor="profile-name">{copy.name}</Label>
          <Input
            id="profile-name"
            maxLength={150}
            value={name}
            disabled={!editsProfile}
            onChange={(event) => setName(event.target.value)}
          />
        </div>
        <div className="flex flex-col gap-1">
          <Label htmlFor="profile-phone">{copy.phone}</Label>
          <Input id="profile-phone" dir="ltr" className="text-start" value={formatPhone(customer.phone)} disabled />
          <p className="text-muted-foreground text-xs">{copy.phoneFixed}</p>
        </div>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <Label htmlFor="profile-credit-limit">{copy.creditLimit}</Label>
          <Input
            id="profile-credit-limit"
            dir="ltr"
            inputMode="decimal"
            className="text-start"
            value={creditLimit}
            disabled={!editsCredit}
            onChange={(event) => setCreditLimit(event.target.value)}
          />
          <p className="text-muted-foreground text-xs">{copy.creditLimitHint}</p>
        </div>
        <div className="flex flex-col gap-1">
          <Label htmlFor="profile-terms">{copy.paymentTerms}</Label>
          <Input
            id="profile-terms"
            dir="ltr"
            inputMode="numeric"
            className="text-start"
            value={terms}
            disabled={!editsCredit}
            onChange={(event) => setTerms(event.target.value)}
          />
          <p className="text-muted-foreground text-xs">{copy.paymentTermsHint}</p>
        </div>
      </div>
      <div className="flex items-center justify-between">
        <h2 className="font-medium">{copy.addresses}</h2>
        {editsProfile && (
          <Button variant="outline" size="sm" onClick={() => setEditing("new")}>
            {copy.addAddress}
          </Button>
        )}
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
              <div className="flex shrink-0 gap-1" hidden={!editsProfile}>
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
      {(editsProfile || editsCredit) && (
        <Button className="self-start" disabled={update.isPending} onClick={save}>
          {update.isPending ? ar.saving : copy.saveProfile}
        </Button>
      )}
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

/**
 * The account statement (BR-C08): the credit picture (limit, what is left under it, unused credit, overdue), a date
 * range in the URL, the ledger with its opening and closing balance, a print view, and the customer's credits.
 */
function Statement({ customerId }: { customerId: number }) {
  const [query, setQuery] = useListQuery(STATEMENT_KEYS);
  const statement = useStatement(customerId, { from: query.from, to: query.to, page: pageOf(query) });
  const labels = copy.statement;

  if (statement.isError) {
    return <ErrorBlock error={statement.error} onRetry={() => void statement.refetch()} />;
  }

  if (statement.data === undefined) {
    return <LoadingBlock rows={4} />;
  }

  const { summary, meta } = statement.data;

  return (
    <div className="flex flex-col gap-4">
      <dl className="grid grid-cols-2 gap-3 md:grid-cols-5" data-testid="statement-summary">
        {(
          [
            [labels.outstanding, formatMoney(summary.outstanding)],
            [labels.creditLimit, summary.credit_limit === null ? copy.noLimit : formatMoney(summary.credit_limit)],
            [
              labels.availableCredit,
              summary.available_credit === null ? copy.noLimit : formatMoney(summary.available_credit),
            ],
            [labels.creditBalance, formatMoney(summary.credit_balance)],
            [labels.overdue, formatMoney(summary.overdue)],
          ] as const
        ).map(([label, value]) => (
          <div key={label} className="rounded-md border p-3">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd
              className={
                label === labels.overdue && summary.overdue !== "0.00"
                  ? "text-destructive text-lg font-bold"
                  : "text-lg font-bold"
              }
            >
              {value}
            </dd>
          </div>
        ))}
      </dl>
      <div className="flex flex-wrap items-end gap-2 print:hidden">
        <label className="flex flex-col gap-1 text-xs">
          {labels.from}
          <Input type="date" value={query.from ?? ""} onChange={(event) => setQuery({ from: event.target.value })} />
        </label>
        <label className="flex flex-col gap-1 text-xs">
          {labels.to}
          <Input type="date" value={query.to ?? ""} onChange={(event) => setQuery({ to: event.target.value })} />
        </label>
        <Button variant="outline" onClick={() => window.print()}>
          {labels.print}
        </Button>
      </div>
      {statement.data.data.length === 0 && meta.opening_balance === "0.00" ? (
        <EmptyBlock>{labels.empty}</EmptyBlock>
      ) : (
        <LedgerTable
          rows={statement.data.data}
          opening={meta.opening_balance}
          closing={meta.closing_balance}
          orderHref={(number) => `/dashboard/orders/${number}`}
        />
      )}
      <div className="print:hidden">
        <Pager meta={meta} onPage={(page) => setQuery({ page })} />
      </div>
      <Credits customerId={customerId} />
    </div>
  );
}

/** BR-C07: the customer's credits with what is left, each unused one voidable with a reason. */
function Credits({ customerId }: { customerId: number }) {
  const credits = useCustomerCredits(customerId);
  const voidCredit = useVoidCredit();
  const labels = copy.credits;

  if (credits.data === undefined || credits.data.length === 0) {
    return null;
  }

  return (
    <Card className="print:hidden">
      <CardHeader>
        <CardTitle>{labels.title}</CardTitle>
      </CardHeader>
      <CardContent>
        <ul className="flex flex-col divide-y" data-testid="customer-credits">
          {credits.data.map((credit) => (
            <li key={credit.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
              <span className="flex flex-col">
                <span className={credit.is_voided ? "text-muted-foreground line-through" : "font-medium"}>
                  {labels.remaining}: {formatMoney(credit.remaining)} · {labels.amount}: {formatMoney(credit.amount)}
                </span>
                <span className="text-muted-foreground text-xs">
                  {formatDateTime(credit.created_at)}
                  {credit.source_return_id !== null && ` · ${labels.fromReturn}`}
                </span>
              </span>
              {credit.is_voided ? (
                <Badge variant="secondary">{labels.voidedBadge}</Badge>
              ) : (
                credit.remaining === credit.amount && (
                  <ReasonDialog
                    trigger={(open) => (
                      <Button variant="ghost" size="sm" className="text-destructive" onClick={open}>
                        {labels.void}
                      </Button>
                    )}
                    title={labels.voidTitle}
                    description={labels.voidBody}
                    confirmLabel={labels.void}
                    destructive
                    onSubmit={async (reason) => {
                      await voidCredit.mutateAsync({ id: credit.id, reason });
                      toast.success(labels.voided);
                    }}
                  />
                )
              )}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
