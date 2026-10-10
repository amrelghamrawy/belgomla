"use client";

import Link from "next/link";
import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ar } from "@/lib/copy/ar";
import { hasFilters } from "@/lib/dashboard/list-query";
import { COLLECTION_LIST_KEYS, useCollections, useVoidCollection } from "@/lib/dashboard/orders";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import type { Collection } from "@/lib/api/types";

import { Pager } from "../common/Pager";
import { ReasonDialog } from "../common/ReasonDialog";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.collections;

/** Every collection, voided included, filtered by Cairo days (D60), customer or order number. */
export function CollectionsPage() {
  const [query, setQuery] = useListQuery(COLLECTION_LIST_KEYS);
  const collections = useCollections(query);
  const [order, setOrder] = useState(query.order ?? "");

  const filterOrder = (event: FormEvent) => {
    event.preventDefault();
    setQuery({ order: order.trim() });
  };

  return (
    <>
      <PageHeader title={copy.title} />
      <div className="flex flex-wrap items-end gap-2">
        <form onSubmit={filterOrder} className="flex gap-2">
          <Input
            aria-label={copy.orderFilter}
            placeholder={copy.orderFilter}
            dir="ltr"
            inputMode="numeric"
            className="w-36 text-start"
            value={order}
            onChange={(event) => setOrder(event.target.value)}
          />
          <Button type="submit" variant="outline">
            {copy.apply}
          </Button>
        </form>
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.from}
          <Input type="date" value={query.from ?? ""} onChange={(event) => setQuery({ from: event.target.value })} />
        </label>
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.to}
          <Input type="date" value={query.to ?? ""} onChange={(event) => setQuery({ to: event.target.value })} />
        </label>
        {hasFilters(query) && (
          <Button
            variant="link"
            onClick={() => {
              setOrder("");
              setQuery(Object.fromEntries(Object.keys(query).map((key) => [key, null])));
            }}
          >
            {ar.dashboard.list.clearFilters}
          </Button>
        )}
      </div>
      {query.customer_id !== undefined && (
        <p className="text-muted-foreground text-sm">{copy.customerFilter(query.customer_id)}</p>
      )}
      {collections.isError && <ErrorBlock error={collections.error} onRetry={() => void collections.refetch()} />}
      {collections.isPending && <LoadingBlock />}
      {collections.data?.data.length === 0 && <EmptyBlock>{copy.none}</EmptyBlock>}
      {collections.data !== undefined && collections.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.when}</TableHead>
                <TableHead>{copy.order}</TableHead>
                <TableHead>{copy.customer}</TableHead>
                <TableHead>{copy.amount}</TableHead>
                <TableHead>{copy.method}</TableHead>
                <TableHead>{ar.dashboard.list.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {collections.data.data.map((collection) => (
                <CollectionRow key={collection.id} collection={collection} />
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={collections.data?.meta} onPage={(page) => setQuery({ page })} />
    </>
  );
}

function CollectionRow({ collection }: { collection: Collection }) {
  const voidCollection = useVoidCollection();

  return (
    <TableRow data-testid={`collection-${collection.id}`}>
      <TableCell className="whitespace-nowrap">{formatDateTime(collection.collected_at)}</TableCell>
      <TableCell>
        <Link href={`/dashboard/orders/${collection.order.number}`} className="font-medium hover:underline">
          <bdi>#{collection.order.number}</bdi>
        </Link>
      </TableCell>
      <TableCell>
        {collection.customer ? (
          <Link href={`/dashboard/customers/${collection.customer.id}`} className="hover:underline">
            {collection.customer.name}
          </Link>
        ) : (
          ar.dashboard.none
        )}
      </TableCell>
      <TableCell
        className={collection.is_voided ? "text-muted-foreground whitespace-nowrap line-through" : "whitespace-nowrap"}
      >
        {formatMoney(collection.amount)}
      </TableCell>
      <TableCell>{collection.method_label}</TableCell>
      <TableCell>
        {collection.is_voided ? (
          <Badge variant="secondary" title={collection.void_reason ?? undefined}>
            {copy.voidedBadge}
          </Badge>
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
              await voidCollection.mutateAsync({ id: collection.id, reason });
              toast.success(copy.voided);
            }}
          />
        )}
      </TableCell>
    </TableRow>
  );
}
