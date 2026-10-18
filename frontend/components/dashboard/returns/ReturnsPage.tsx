"use client";

import Link from "next/link";
import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { OrderReturn } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { activeFilters, hasFilters } from "@/lib/dashboard/list-query";
import { RETURN_LIST_KEYS, useReturns, useVoidReturn } from "@/lib/dashboard/returns";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";

import { FilterPanel } from "../common/FilterPanel";
import { Pager } from "../common/Pager";
import { ReasonDialog } from "../common/ReasonDialog";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.returns;

/** Every return of the store, voided included, newest first, filtered by Cairo days (D60) and a search (BR-R05). */
export function ReturnsPage() {
  const [query, setQuery] = useListQuery(RETURN_LIST_KEYS);
  const returns = useReturns(query);
  const [q, setQ] = useState(query.q ?? "");

  const search = (event: FormEvent) => {
    event.preventDefault();
    setQuery({ q: q.trim() });
  };

  return (
    <>
      <PageHeader title={copy.title} />
      <FilterPanel
        active={activeFilters(query, ["from", "to"])}
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
              placeholder={copy.search}
              value={q}
              onChange={(event) => setQ(event.target.value)}
            />
            <Button type="submit" variant="outline">
              {ar.dashboard.collections.apply}
            </Button>
          </form>
        }
      >
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.from}
          <Input type="date" value={query.from ?? ""} onChange={(event) => setQuery({ from: event.target.value })} />
        </label>
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.to}
          <Input type="date" value={query.to ?? ""} onChange={(event) => setQuery({ to: event.target.value })} />
        </label>
      </FilterPanel>
      {returns.isError && <ErrorBlock error={returns.error} onRetry={() => void returns.refetch()} />}
      {returns.isPending && <LoadingBlock />}
      {returns.data?.data.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {returns.data !== undefined && returns.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.when}</TableHead>
                <TableHead>{copy.order}</TableHead>
                <TableHead>{copy.customer}</TableHead>
                <TableHead>{copy.amount}</TableHead>
                <TableHead>{ar.dashboard.list.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {returns.data.data.map((orderReturn) => (
                <ReturnRow key={orderReturn.id} orderReturn={orderReturn} />
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={returns.data?.meta} onPage={(page) => setQuery({ page })} />
    </>
  );
}

function ReturnRow({ orderReturn }: { orderReturn: OrderReturn }) {
  const voidReturn = useVoidReturn();

  return (
    <TableRow data-testid={`return-${orderReturn.id}`}>
      <TableCell className="whitespace-nowrap">{formatDateTime(orderReturn.returned_at)}</TableCell>
      <TableCell>
        {orderReturn.order ? (
          <Link href={`/dashboard/orders/${orderReturn.order.number}`} className="font-medium hover:underline">
            <bdi>#{orderReturn.order.number}</bdi> · {copy.returnNumber(orderReturn.number)}
          </Link>
        ) : (
          copy.returnNumber(orderReturn.number)
        )}
        <p className="text-muted-foreground max-w-56 text-xs">{orderReturn.reason}</p>
      </TableCell>
      <TableCell>
        {orderReturn.customer ? (
          <Link href={`/dashboard/customers/${orderReturn.customer.id}`} className="hover:underline">
            {orderReturn.customer.name}
          </Link>
        ) : (
          ar.dashboard.none
        )}
      </TableCell>
      <TableCell
        className={orderReturn.is_voided ? "text-muted-foreground whitespace-nowrap line-through" : "whitespace-nowrap"}
      >
        {formatMoney(orderReturn.total)}
      </TableCell>
      <TableCell>
        {orderReturn.is_voided ? (
          <div className="flex flex-col items-start gap-1">
            <Badge variant="secondary">{copy.voidedBadge}</Badge>
            {orderReturn.void_reason !== null && (
              <span className="text-muted-foreground max-w-48 text-xs">{orderReturn.void_reason}</span>
            )}
          </div>
        ) : (
          <ReasonDialog
            trigger={(open) => (
              <Button variant="ghost" size="sm" className="text-destructive" onClick={open}>
                {copy.void}
              </Button>
            )}
            title={copy.voidTitle}
            description={copy.voidBody}
            confirmLabel={copy.void}
            destructive
            onSubmit={async (reason) => {
              await voidReturn.mutateAsync({ id: orderReturn.id, reason });
              toast.success(copy.voided);
            }}
          />
        )}
      </TableCell>
    </TableRow>
  );
}
