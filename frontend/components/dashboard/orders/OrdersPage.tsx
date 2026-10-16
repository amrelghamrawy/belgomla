"use client";

import Link from "next/link";
import { type FormEvent, useState } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { CollectionStatus, OrderStatus } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { activeFilters, hasFilters } from "@/lib/dashboard/list-query";
import { ORDER_LIST_KEYS, useOrders } from "@/lib/dashboard/orders";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { cn } from "@/lib/utils";

import { CustomerPicker } from "../common/CustomerPicker";
import { FilterPanel } from "../common/FilterPanel";
import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { CollectionStatusBadge, OrderStatusBadge, StaleBadge } from "./OrderBadges";

const copy = ar.dashboard.orders;
const STATUSES: OrderStatus[] = ["pending", "confirmed", "preparing", "out_for_delivery", "delivered", "cancelled"];
const COLLECTIONS: CollectionStatus[] = ["unpaid", "partially_collected", "collected"];

/** Orders with status tabs, collection status, the stale badge (D10) and filters, all in the URL. */
export function OrdersPage() {
  const [query, setQuery] = useListQuery(ORDER_LIST_KEYS);
  const orders = useOrders(query);
  const [q, setQ] = useState(query.q ?? "");

  const search = (event: FormEvent) => {
    event.preventDefault();
    setQuery({ q: q.trim() });
  };

  return (
    <>
      <PageHeader title={copy.title} />
      <nav aria-label={copy.status} className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
        {[undefined, ...STATUSES].map((status) => {
          const active = query.status === status;

          return (
            <Button
              key={status ?? "all"}
              size="sm"
              variant={active ? "default" : "outline"}
              aria-current={active ? "page" : undefined}
              className="shrink-0"
              onClick={() => setQuery({ status: status ?? null })}
            >
              {status === undefined ? copy.all : ar.orders.statuses[status]}
            </Button>
          );
        })}
      </nav>
      <FilterPanel
        active={activeFilters(query, ["collection_status", "from", "to", "sort", "stale"])}
        onClear={
          hasFilters(query)
            ? () => {
                setQ("");
                setQuery(Object.fromEntries(Object.keys(query).map((key) => [key, null])));
              }
            : undefined
        }
        leading={
          <>
            <form onSubmit={search} role="search" className="flex gap-2">
              <Input
                aria-label={ar.dashboard.list.search}
                placeholder={copy.number}
                value={q}
                onChange={(event) => setQ(event.target.value)}
                className="w-40"
              />
              <Button type="submit" variant="outline">
                {ar.dashboard.list.search}
              </Button>
            </form>
            <CustomerPicker value={query.customer_id} onChange={(id) => setQuery({ customer_id: id })} />
          </>
        }
      >
        <NativeSelect
          aria-label={copy.collection}
          value={query.collection_status ?? ""}
          onChange={(event) => setQuery({ collection_status: event.target.value })}
        >
          <option value="">{copy.allCollections}</option>
          {COLLECTIONS.map((status) => (
            <option key={status} value={status}>
              {ar.dashboard.collectionStatuses[status]}
            </option>
          ))}
        </NativeSelect>
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.from}
          <Input type="date" value={query.from ?? ""} onChange={(event) => setQuery({ from: event.target.value })} />
        </label>
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.to}
          <Input type="date" value={query.to ?? ""} onChange={(event) => setQuery({ to: event.target.value })} />
        </label>
        <NativeSelect
          aria-label={copy.sort}
          value={query.sort ?? "-placed_at"}
          onChange={(event) => setQuery({ sort: event.target.value === "-placed_at" ? null : event.target.value })}
        >
          {Object.entries(copy.sorts).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </NativeSelect>
        <div className="flex h-10 items-center gap-2">
          <Switch
            id="orders-stale"
            checked={query.stale === "1"}
            onCheckedChange={(checked) => setQuery({ stale: checked ? "1" : null })}
          />
          <Label htmlFor="orders-stale">{copy.staleOnly}</Label>
        </div>
      </FilterPanel>
      {orders.isError && <ErrorBlock error={orders.error} onRetry={() => void orders.refetch()} />}
      {orders.isPending && <LoadingBlock />}
      {orders.data?.data.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {orders.data !== undefined && orders.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.number}</TableHead>
                <TableHead>{copy.customer}</TableHead>
                <TableHead>{copy.placedAt}</TableHead>
                <TableHead>{copy.status}</TableHead>
                <TableHead>{copy.collection}</TableHead>
                <TableHead>{copy.total}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {orders.data.data.map((order) => (
                <TableRow
                  key={order.number}
                  data-testid={`order-${order.number}`}
                  className={cn(order.is_stale && "bg-amber-50")}
                >
                  <TableCell className="font-medium">
                    <Link href={`/dashboard/orders/${order.number}`} className="hover:underline">
                      <bdi>#{order.number}</bdi>
                    </Link>
                  </TableCell>
                  <TableCell>{order.customer?.name ?? order.delivery.name}</TableCell>
                  <TableCell className="whitespace-nowrap">{formatDateTime(order.placed_at)}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    <span className="flex items-center gap-1">
                      <OrderStatusBadge status={order.status} />
                      {order.is_stale && <StaleBadge />}
                    </span>
                  </TableCell>
                  <TableCell>
                    <CollectionStatusBadge status={order.collection_status} />
                  </TableCell>
                  <TableCell className="whitespace-nowrap">{formatMoney(order.order_total)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={orders.data?.meta} onPage={(page) => setQuery({ page })} />
    </>
  );
}
