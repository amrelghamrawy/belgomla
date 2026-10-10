"use client";

import { ChevronRightIcon } from "lucide-react";
import Link from "next/link";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ar } from "@/lib/copy/ar";
import { useInventoryRow, useMovements } from "@/lib/dashboard/inventory";
import { pageOf } from "@/lib/dashboard/list-query";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatDateTime } from "@/lib/format/date";
import { useRouteParam } from "@/lib/store/route";
import { cn } from "@/lib/utils";

import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { AdjustStockSheet } from "./AdjustStockSheet";

const copy = ar.dashboard.inventory;

/** One SKU's stock movements, newest first (BR-INV06). */
export function MovementsPage() {
  const skuId = Number(useRouteParam("sku"));
  const [query, setQuery] = useListQuery([]);
  const page = pageOf(query);
  const [adjusting, setAdjusting] = useState(false);
  const row = useInventoryRow(skuId);
  const movements = useMovements(skuId, page);

  return (
    <>
      <Button asChild variant="link" className="self-start px-0">
        <Link href="/dashboard/inventory">
          <ChevronRightIcon className="rtl:rotate-180" />
          {copy.back}
        </Link>
      </Button>
      <PageHeader
        title={copy.movementsTitle(row.data?.code ?? "")}
        description={row.data?.name ?? undefined}
        actions={row.data && <Button onClick={() => setAdjusting(true)}>{copy.adjust}</Button>}
      />
      {row.data && (
        <dl className="grid max-w-md grid-cols-3 gap-2 text-center">
          {(
            [
              [copy.onHand, row.data.on_hand],
              [copy.reserved, row.data.reserved],
              [copy.available, row.data.available],
            ] as const
          ).map(([label, value]) => (
            <div key={label} className="rounded-md border p-2">
              <dt className="text-muted-foreground text-xs">{label}</dt>
              <dd className="text-lg font-bold">{value}</dd>
            </div>
          ))}
        </dl>
      )}
      {movements.isError && <ErrorBlock error={movements.error} onRetry={() => void movements.refetch()} />}
      {movements.isPending && <LoadingBlock />}
      {movements.data?.data.length === 0 && <EmptyBlock>{copy.noMovements}</EmptyBlock>}
      {movements.data !== undefined && movements.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.when}</TableHead>
                <TableHead>{copy.type}</TableHead>
                <TableHead>{copy.quantity}</TableHead>
                <TableHead>{copy.after}</TableHead>
                <TableHead>{copy.movementReason}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {movements.data.data.map((movement) => (
                <TableRow key={movement.id}>
                  <TableCell className="whitespace-nowrap">{formatDateTime(movement.created_at)}</TableCell>
                  <TableCell>{movement.type_label}</TableCell>
                  <TableCell
                    dir="ltr"
                    className={cn("text-end", movement.quantity < 0 ? "text-destructive" : "text-emerald-700")}
                  >
                    {movement.quantity > 0 ? `+${movement.quantity}` : movement.quantity}
                  </TableCell>
                  <TableCell>{movement.on_hand_after}</TableCell>
                  <TableCell>{movement.reason ?? ar.dashboard.none}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={movements.data?.meta} onPage={(next) => setQuery({ page: next })} />
      {adjusting && row.data && <AdjustStockSheet row={row.data} onClose={() => setAdjusting(false)} />}
    </>
  );
}
